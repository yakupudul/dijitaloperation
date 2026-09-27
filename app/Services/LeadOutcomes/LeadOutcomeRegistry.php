<?php

namespace App\Services\LeadOutcomes;

use App\Models\Brand;
use App\Models\LeadOutcome;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Registers client-side leads for the lead quality loop and records their outcome. MoxDOP has no client lead
 * feed (Meta `leads_retrieval` is a forbidden permission and `/api/leads` is only the agency's own form), so the
 * operator imports the lead export of the form tool / Meta Lead Center or adds a lead by hand. Only the lead id,
 * time, campaign / form name and a short matching hint are kept: names, phone numbers and e-mails are dropped.
 */
final class LeadOutcomeRegistry
{
    public const int MAX_IMPORT_ROWS = 5000;

    /** header (lower-case) → field */
    private const array HEADERS = [
        'ref' => ['id', 'lead_id', 'leadgen_id', 'lead id', 'entry_id', 'entry id', 'kayıt no', 'kayit no'],
        'received' => ['created_time', 'created_at', 'created', 'date', 'tarih', 'submitted_at', 'submission date', 'zaman', 'time'],
        'campaign' => ['campaign_name', 'campaign name', 'ad_name', 'ad name', 'form_name', 'form name', 'adset_name', 'kampanya', 'form', 'kaynak'],
        'name' => ['full_name', 'full name', 'name', 'ad soyad', 'adı soyadı', 'adi soyadi', 'first_name', 'isim'],
        'phone' => ['phone_number', 'phone number', 'phone', 'telefon', 'tel', 'cep telefonu'],
    ];

    /**
     * One lead added by hand (e.g. a phone call the clinic told about).
     *
     * @param  array{lead_source: string, lead_received_at: string, campaign_label?: ?string, contact_hint?: ?string, lead_ref?: ?string}  $input
     */
    public function add(Brand $brand, array $input, ?User $by): LeadOutcome
    {
        $source = (string) ($input['lead_source'] ?? '');
        if (! array_key_exists($source, LeadOutcome::SOURCES)) {
            throw ValidationException::withMessages(['lead_source' => 'Geçerli bir lead kaynağı seçin.']);
        }
        $received = $this->date((string) ($input['lead_received_at'] ?? ''));
        if ($received === null || $received->isFuture()) {
            throw ValidationException::withMessages(['lead_received_at' => 'Geçerli bir geliş tarihi girin.']);
        }
        $ref = trim((string) ($input['lead_ref'] ?? ''));

        return LeadOutcome::query()->firstOrCreate(
            ['brand_id' => $brand->id, 'lead_source' => $source, 'lead_ref' => $ref !== '' ? mb_substr($ref, 0, 120) : 'manual:'.Str::uuid()],
            [
                'customer_id' => $brand->customer_id,
                'lead_received_at' => $received,
                'campaign_label' => $this->clip($input['campaign_label'] ?? null, 160),
                'contact_hint' => $this->clip($input['contact_hint'] ?? null, 40),
                'status' => LeadOutcome::STATUS_NEW,
                'created_by' => $by?->id,
            ],
        );
    }

    /**
     * Imports a lead export (CSV / TSV; UTF-8, UTF-8 BOM or UTF-16 as Meta Lead Center writes it). Existing leads
     * (same brand, source and id) are left untouched, so the same file can be imported again.
     *
     * @return array{created: int, existing: int, skipped: int}
     */
    public function import(Brand $brand, string $source, string $content, ?User $by): array
    {
        if (! array_key_exists($source, LeadOutcome::SOURCES)) {
            throw ValidationException::withMessages(['importSource' => 'Geçerli bir lead kaynağı seçin.']);
        }
        $rows = $this->parse($content);
        if ($rows === []) {
            throw ValidationException::withMessages(['importFile' => 'Dosyada başlık satırı ve en az bir lead olmalı.']);
        }
        $map = $this->columns(array_shift($rows));
        if (! isset($map['received'])) {
            throw ValidationException::withMessages(['importFile' => 'Tarih sütunu bulunamadı (ör. created_time, date, tarih).']);
        }
        if (count($rows) > self::MAX_IMPORT_ROWS) {
            throw ValidationException::withMessages(['importFile' => 'Bir seferde en fazla '.self::MAX_IMPORT_ROWS.' lead içe aktarılabilir.']);
        }
        $stats = ['created' => 0, 'existing' => 0, 'skipped' => 0];
        foreach ($rows as $row) {
            $value = static fn (string $field): string => isset($map[$field]) ? trim((string) ($row[$map[$field]] ?? '')) : '';
            $received = $this->date($value('received'));
            if ($received === null || $received->isAfter(now()->addDay())) {
                $stats['skipped']++;

                continue;
            }
            $name = $value('name');
            $phone = $value('phone');
            $ref = $value('ref');
            if ($ref === '') {
                // No id column: a keyed hash of the row, so a re-import finds the same lead without storing contact data.
                $ref = 'row:'.substr(hash_hmac('sha256', $received->toIso8601String().'|'.$name.'|'.$phone.'|'.$value('campaign'), (string) config('app.key')), 0, 40);
            }
            $lead = LeadOutcome::query()->firstOrCreate(
                ['brand_id' => $brand->id, 'lead_source' => $source, 'lead_ref' => mb_substr($ref, 0, 120)],
                [
                    'customer_id' => $brand->customer_id,
                    'lead_received_at' => $received,
                    'campaign_label' => $this->clip($value('campaign'), 160),
                    'contact_hint' => self::hint($name, $phone),
                    'status' => LeadOutcome::STATUS_NEW,
                    'created_by' => $by?->id,
                ],
            );
            $stats[$lead->wasRecentlyCreated ? 'created' : 'existing']++;
        }

        return $stats;
    }

    public function mark(LeadOutcome $lead, string $status, ?User $by, ?float $value = null, ?string $note = null): LeadOutcome
    {
        if (! array_key_exists($status, LeadOutcome::STATUSES)) {
            throw ValidationException::withMessages(['status' => 'Geçersiz sonuç.']);
        }
        if ($value !== null && ($value < 0 || $value > 100_000_000)) {
            throw ValidationException::withMessages(['value' => 'Değer 0 ile 100.000.000 TL arasında olmalı.']);
        }
        $lead->forceFill([
            'status' => $status,
            'value_try' => $value,
            'note' => $this->clip($note, 500),
            'marked_by' => $status === LeadOutcome::STATUS_NEW ? null : $by?->id,
            'marked_at' => $status === LeadOutcome::STATUS_NEW ? null : now(),
        ])->save();

        return $lead;
    }

    /** "A.Y. ••4512": initials and the last four digits, enough to match the clinic's feedback. */
    public static function hint(?string $name, ?string $phone): ?string
    {
        $initials = collect(preg_split('/\s+/u', trim((string) $name)) ?: [])->filter()->take(2)
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)).'.')->implode('');
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        $tail = strlen($digits) >= 4 ? '••'.substr($digits, -4) : '';
        $hint = trim($initials.' '.$tail);

        return $hint !== '' ? $hint : null;
    }

    /** @return list<list<string>> */
    private function parse(string $content): array
    {
        if (str_starts_with($content, "\xFF\xFE")) {
            $content = mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16LE');
        } elseif (str_starts_with($content, "\xFE\xFF")) {
            $content = mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16BE');
        } elseif (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1254');
        }
        $firstLine = strtok($content, "\n") ?: '';
        $delimiter = collect(["\t", ';', ','])->sortByDesc(fn (string $d): int => substr_count($firstLine, $d))->first();
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            if ($row === [null] || implode('', array_map('strval', $row)) === '') {
                continue;
            }
            $rows[] = array_map(static fn ($cell): string => (string) $cell, $row);
        }
        fclose($stream);

        return $rows;
    }

    /**
     * @param  list<string>  $header
     * @return array<string, int>
     */
    private function columns(array $header): array
    {
        $map = [];
        foreach ($header as $index => $label) {
            $label = mb_strtolower(trim($label, " \t\"'"));
            foreach (self::HEADERS as $field => $names) {
                if (! isset($map[$field]) && in_array($label, $names, true)) {
                    $map[$field] = $index;
                }
            }
        }

        return $map;
    }

    private function date(string $value): ?CarbonImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        foreach (['d.m.Y H:i', 'd.m.Y', 'd/m/Y H:i', 'd/m/Y'] as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat('!'.$format, $value, config('app.timezone'));
            } catch (Throwable) {
                $parsed = null;
            }
            if ($parsed instanceof CarbonImmutable && $parsed->format($format) === $value) {
                return $parsed;
            }
        }
        try {
            return CarbonImmutable::parse($value, config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
    }

    private function clip(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? mb_substr($value, 0, $max) : null;
    }
}
