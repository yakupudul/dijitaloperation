<?php

namespace App\Services\Brand;

use App\Models\Brand;
use App\Models\BrandFinding;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Marka sayfası › Bulgular (Marka Düzeni 2.1 / 2.2). Claude writes a brand's findings when it is connected (MCP
 * brand-findings), each with its evidence; the operator presses "Çöz" (or "Hepsini çöz") and the row goes to Claude,
 * who does the work with its usual tools and writes the result on the same row. Nothing here writes outside MoxDOP:
 * the approval kind on the row says what the work will still ask of the operator.
 */
final class Findings
{
    /**
     * Writes (or updates, by id) one row. Only Claude's MCP tool and tests call this.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(Brand $brand, array $data, ?int $id = null): BrandFinding
    {
        $kind = (string) ($data['kind'] ?? 'finding');
        $approval = (string) ($data['approval'] ?? 'none');
        $title = trim((string) ($data['title'] ?? ''));
        $errors = [];
        if (! isset(BrandFinding::KINDS[$kind])) {
            $errors['kind'] = 'kind: '.implode(' | ', array_keys(BrandFinding::KINDS));
        }
        if (! isset(BrandFinding::APPROVALS[$approval])) {
            $errors['approval'] = 'approval: '.implode(' | ', array_keys(BrandFinding::APPROVALS));
        }
        if ($title === '' || mb_strlen($title) > 255) {
            $errors['title'] = 'title boş olamaz, en çok 255 karakter.';
        }
        if ($kind === 'finding' && trim((string) ($data['evidence'] ?? '')) === '') {
            $errors['evidence'] = 'Bulgu kanıtsız yazılmaz: evidence alanına sayıyı ya da adresi yaz.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $row = $id !== null ? BrandFinding::query()->where('brand_id', $brand->id)->findOrFail($id) : new BrandFinding(['brand_id' => $brand->id, 'status' => 'open']);
        $row->fill([
            'kind' => $kind, 'area' => mb_substr(trim((string) ($data['area'] ?? 'Diğer')) ?: 'Diğer', 0, 40), 'title' => $title,
            'evidence' => self::text($data['evidence'] ?? null, 500), 'saw' => self::text($data['saw'] ?? null, 4000),
            'plan' => self::text($data['plan'] ?? null, 4000), 'needs' => self::text($data['needs'] ?? null, 2000), 'approval' => $approval,
        ])->save();

        return $row;
    }

    /** "Çöz": the row goes to Claude. */
    public function solve(BrandFinding $row, ?User $user = null): void
    {
        if ($row->kind !== 'finding' || $row->status !== 'open') {
            return;
        }
        $row->forceFill(['status' => 'requested', 'requested_at' => now(), 'requested_by' => $user?->id])->save();
    }

    /** "Hepsini çöz": every open finding of the brand. Returns how many went. */
    public function solveAll(Brand $brand, ?User $user = null): int
    {
        $rows = BrandFinding::query()->where('brand_id', $brand->id)->where('kind', 'finding')->where('status', 'open')->get();
        $rows->each(fn (BrandFinding $row) => $this->solve($row, $user));

        return $rows->count();
    }

    /** The operator takes a row back before Claude started, puts it aside, or reopens it. */
    public function setStatus(BrandFinding $row, string $status): void
    {
        $allowed = match ($row->status) {
            'open' => ['closed', 'done'], 'requested' => ['open'], 'waiting' => ['done', 'open'], 'closed', 'done' => ['open'], default => [],
        };
        if (! in_array($status, $allowed, true)) {
            return;
        }
        $row->forceFill(['status' => $status, 'resolved_at' => in_array($status, ['done', 'closed'], true) ? now() : null,
            'requested_at' => $status === 'open' ? null : $row->requested_at])->save();
    }

    /**
     * Claude's result on a requested row: done, or waiting when the operator's approval is still needed (the link says where).
     */
    public function result(BrandFinding $row, string $result, ?string $url = null, bool $waiting = false): void
    {
        $row->forceFill(['result' => self::text($result, 4000), 'result_url' => self::text($url, 500), 'status' => $waiting ? 'waiting' : 'done',
            'resolved_at' => $waiting ? null : now()])->save();
    }

    /** A month later: what the work changed (a number, before → after). */
    public function effect(BrandFinding $row, string $effect): void
    {
        $row->forceFill(['effect' => self::text($effect, 2000)])->save();
    }

    /**
     * The brand page's sections.
     *
     * @return array{live: Collection<int, BrandFinding>, tasks: Collection<int, BrandFinding>, untouched: Collection<int, BrandFinding>, unseen: Collection<int, BrandFinding>, done: Collection<int, BrandFinding>, open: int, any: bool}
     */
    public function page(Brand $brand): array
    {
        $rows = BrandFinding::query()->where('brand_id', $brand->id)->orderBy('id')->get();
        $order = ['waiting' => 0, 'open' => 1, 'requested' => 2];

        return [
            'live' => $rows->filter(fn (BrandFinding $r): bool => $r->kind === 'finding' && isset($order[$r->status]))->sortBy(fn (BrandFinding $r): int => $order[$r->status] * 100000 + $r->id)->values(),
            'tasks' => $rows->filter(fn (BrandFinding $r): bool => $r->kind === 'task' && $r->status === 'open')->values(),
            'untouched' => $rows->where('kind', 'untouched')->values(),
            'unseen' => $rows->where('kind', 'unseen')->values(),
            'done' => $rows->filter(fn (BrandFinding $r): bool => in_array($r->kind, ['finding', 'task'], true) && $r->status === 'done')->sortByDesc('resolved_at')->values(),
            'open' => $rows->filter(fn (BrandFinding $r): bool => $r->kind === 'finding' && $r->status === 'open')->count(),
            'any' => $rows->isNotEmpty(),
        ];
    }

    /** How many rows wait in Claude's hands (list-tasks tells it). */
    public static function requested(): int
    {
        return BrandFinding::query()->where('status', 'requested')->count();
    }

    private static function text(mixed $value, int $max): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text !== '' ? mb_substr($text, 0, $max) : null;
    }
}
