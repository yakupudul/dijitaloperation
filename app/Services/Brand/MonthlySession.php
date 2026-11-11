<?php

namespace App\Services\Brand;

use App\Models\Brand;
use App\Models\BrandBaseline;
use App\Models\BrandFinding;
use App\Models\BrandSession;
use App\Models\User;
use App\Support\SchemaState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Aylık oturum (Marka Düzeni 2.6). Once a month Claude reads a brand (numbers against the start numbers, what was done,
 * what did not work) and writes ONE session: a summary for the operator, at most five jobs for the coming month and the
 * lines of a one-page report in the customer's language. The operator approves once: the jobs become findings already
 * handed to Claude ("Çöz" pressed for all), and the report page (`/rapor/{token}`) is ready to be sent by the operator.
 * Nothing here writes outside MoxDOP and nothing is sent to the customer by the system.
 */
final class MonthlySession
{
    public const int MAX_JOBS = 5;

    public const int MAX_LINES = 8;

    public function __construct(private readonly Findings $findings, private readonly Baseline $baseline) {}

    /**
     * Claude writes (or rewrites, until it is approved) the month's session.
     *
     * @param  array<string, mixed>  $data  summary, jobs[], client_note, report_lines[], month (Y-m)
     */
    public function write(Brand $brand, array $data): BrandSession
    {
        $month = (string) ($data['month'] ?? CarbonImmutable::now('Europe/Istanbul')->format('Y-m'));
        $summary = trim((string) ($data['summary'] ?? ''));
        $jobs = array_values(array_filter((array) ($data['jobs'] ?? []), 'is_array'));
        $lines = array_values(array_filter((array) ($data['report_lines'] ?? []), 'is_array'));
        $errors = [];
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            $errors['month'] = 'month: YYYY-MM.';
        }
        if ($summary === '') {
            $errors['summary'] = 'summary boş olamaz: ayın özeti, sayılarla.';
        }
        if (count($jobs) > self::MAX_JOBS) {
            $errors['jobs'] = 'Bir oturumda en çok '.self::MAX_JOBS.' iş olur; en çok etki edecek '.self::MAX_JOBS.' işi seç.';
        }
        if (count($lines) > self::MAX_LINES) {
            $errors['report_lines'] = 'Rapor bir sayfadır: en çok '.self::MAX_LINES.' satır.';
        }
        foreach ($jobs as $i => $job) {
            if (trim((string) ($job['title'] ?? '')) === '' || trim((string) ($job['evidence'] ?? '')) === '') {
                $errors['jobs.'.$i] = 'Her işin title ve evidence alanı dolu olmalı (kanıtsız iş yazılmaz).';
            }
            if (! isset(BrandFinding::APPROVALS[(string) ($job['approval'] ?? 'none')])) {
                $errors['jobs.'.$i.'.approval'] = 'approval: '.implode(' | ', array_keys(BrandFinding::APPROVALS));
            }
        }
        foreach ($lines as $i => $line) {
            if (trim((string) ($line['what'] ?? '')) === '') {
                $errors['report_lines.'.$i] = 'Her rapor satırının what alanı dolu olmalı.';
            }
        }
        $existing = $errors === [] ? BrandSession::query()->where('brand_id', $brand->id)->where('month', $month)->first() : null;
        if ($existing?->isApproved()) {
            $errors['month'] = $existing->monthLabel().' oturumu onaylandı, değiştirilemez. Yeni iş için bulgu yaz ya da gelecek ayın oturumunu bekle.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $card = $this->baseline->card($brand);
        $session = $existing ?? new BrandSession(['brand_id' => $brand->id, 'month' => $month, 'token' => Str::random(48), 'status' => 'draft']);
        $session->fill([
            'summary' => mb_substr($summary, 0, 4000),
            'jobs' => array_map(fn (array $job): array => [
                'title' => mb_substr(trim((string) $job['title']), 0, 255), 'area' => mb_substr(trim((string) ($job['area'] ?? 'Diğer')) ?: 'Diğer', 0, 40),
                'evidence' => mb_substr(trim((string) $job['evidence']), 0, 500), 'plan' => mb_substr(trim((string) ($job['plan'] ?? '')), 0, 4000),
                'needs' => mb_substr(trim((string) ($job['needs'] ?? '')), 0, 2000), 'approval' => (string) ($job['approval'] ?? 'none'),
            ], $jobs),
            'client_note' => ($note = trim((string) ($data['client_note'] ?? ''))) !== '' ? mb_substr($note, 0, 1500) : null,
            'report_lines' => array_map(fn (array $line): array => [
                'what' => mb_substr(trim((string) $line['what']), 0, 200), 'result' => mb_substr(trim((string) ($line['result'] ?? '')), 0, 400),
            ], $lines),
            'numbers' => $card !== null ? [
                'from' => $card['baseline']->period_from->toDateString(), 'to' => $card['to']->toDateString(),
                'lines' => $card['lines'], 'services' => $card['services'], 'measured' => $card['measured'],
            ] : null,
        ])->save();

        return $session;
    }

    /** The session the brand page shows: this month's, else the newest one. */
    public function current(Brand $brand): ?BrandSession
    {
        if (! SchemaState::hasTable('brand_sessions')) {
            return null;
        }

        return BrandSession::query()->where('brand_id', $brand->id)->orderByDesc('month')->first();
    }

    /** The one approval: every job becomes a finding in Claude's hands. Returns how many jobs went. */
    public function approve(BrandSession $session, ?User $user = null): int
    {
        if ($session->isApproved()) {
            return 0;
        }

        return DB::transaction(function () use ($session, $user): int {
            $jobs = [];
            foreach ((array) $session->jobs as $job) {
                $row = $this->findings->save($session->brand, ['kind' => 'finding'] + $job + ['saw' => $session->monthLabel().' oturumunda seçildi.']);
                $this->findings->solve($row, $user);
                $jobs[] = $job + ['finding_id' => $row->id];
            }
            $session->forceFill(['jobs' => $jobs, 'status' => 'approved', 'approved_at' => now(), 'approved_by' => $user?->id])->save();

            return count($jobs);
        });
    }

    /**
     * Brands whose month is due: in the new order, start numbers at least 30 days old, no session written this month.
     *
     * @return list<array{brand_id: int, brand: string}>
     */
    public static function due(): array
    {
        if (! SchemaState::hasTable('brand_sessions') || ! SchemaState::hasTable('brand_baselines')) {
            return [];
        }
        $month = CarbonImmutable::now('Europe/Istanbul')->format('Y-m');

        return Brand::query()->whereNotNull('new_order_at')
            ->whereIn('id', BrandBaseline::query()->where('period_to', '<=', CarbonImmutable::now('Europe/Istanbul')->subDays(Baseline::DAYS)->toDateString())->select('brand_id'))
            ->whereNotIn('id', BrandSession::query()->where('month', $month)->select('brand_id'))
            ->orderBy('name')->limit(20)->get(['id', 'name'])
            ->map(fn (Brand $brand): array => ['brand_id' => (int) $brand->id, 'brand' => (string) $brand->name])->all();
    }

    /** How many brands have a session waiting for the operator's approval. */
    public static function waiting(): int
    {
        return SchemaState::hasTable('brand_sessions') ? BrandSession::query()->where('status', 'draft')->count() : 0;
    }
}
