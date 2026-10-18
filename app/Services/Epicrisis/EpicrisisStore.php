<?php

namespace App\Services\Epicrisis;

use App\Models\Brand;
use App\Models\BrandEpicrisisItem;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Collection;

/**
 * Writes epikriz lines: one row per brand, layer, field, scope and value (the same value seen by two sources is one row
 * that remembers both), each producer (the rules, the AI site reading, the synthesis, the lessons) owning what it wrote
 * last; a line no producer sees any more is dropped unless the operator locked it. Then confidence, conflicts and
 * expiry are worked out by rules.
 */
final class EpicrisisStore
{
    /** Sources whose word is enough on its own. */
    public const array OPERATOR_SOURCES = ['operator', 'chat'];

    /**
     * @param  list<array<string, mixed>>  $rows  field, value, scope?, source_kind, source_ref?, source_text?, confidence?, detail?
     * @return list<int> ids of the rows written or seen
     */
    public function sync(Brand $brand, string $layer, array $rows, string $producer): array
    {
        $now = now();
        $seen = [];
        foreach ($rows as $row) {
            $value = trim((string) ($row['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            $field = (string) $row['field'];
            $scope = isset($row['scope']) && trim((string) $row['scope']) !== '' ? trim((string) $row['scope']) : null;
            $fingerprint = self::fingerprint($layer, $field, $scope, $value);
            $item = BrandEpicrisisItem::query()->firstOrNew(['brand_id' => $brand->id, 'fingerprint' => $fingerprint]);
            $detail = array_merge((array) ($item->detail ?? []), (array) ($row['detail'] ?? []));
            $detail['producers'] = array_values(array_unique([...(array) ($detail['producers'] ?? []), $producer]));
            $detail['sources'] = array_values(array_unique([...(array) ($detail['sources'] ?? []), (string) $row['source_kind']]));
            $days = (int) (config('moxdop-epicrisis.fields.'.$field.'.days') ?? 365);
            $fill = ['layer' => $layer, 'field' => $field, 'scope' => $scope, 'value' => $value, 'detail' => $detail, 'seen_at' => $now, 'expires_at' => $now->copy()->addDays($days)];
            if (! $item->exists || ! $item->locked) {
                $fill += ['source_kind' => (string) $row['source_kind'], 'source_ref' => isset($row['source_ref']) ? mb_substr((string) $row['source_ref'], 0, 400) : null,
                    'source_text' => isset($row['source_text']) ? mb_substr((string) $row['source_text'], 0, 400) : null];
            }
            $live = $layer === BrandEpicrisisItem::QUESTION ? BrandEpicrisisItem::OPEN : BrandEpicrisisItem::ACTIVE;
            if (! $item->exists) {
                $fill += ['confidence' => (string) ($row['confidence'] ?? BrandEpicrisisItem::DATA), 'status' => $live, 'locked' => (bool) ($row['locked'] ?? false)];
            } elseif (in_array($item->status, [BrandEpicrisisItem::DROPPED, BrandEpicrisisItem::EXPIRED], true) && ! data_get($item->detail, 'superseded')) {
                $fill['status'] = $live;
            }
            if (isset($row['confidence']) && $row['confidence'] === BrandEpicrisisItem::VERIFIED) {
                $fill['confidence'] = BrandEpicrisisItem::VERIFIED;
            }
            $item->forceFill($fill)->save();
            $seen[] = (int) $item->id;
        }
        // What this producer no longer sees: it lets go; a line nobody holds is dropped (an operator-locked line, an
        // answered or dismissed question stays).
        BrandEpicrisisItem::query()->where('brand_id', $brand->id)->where('layer', $layer)->whereNotIn('id', $seen ?: [0])->get()
            ->filter(fn (BrandEpicrisisItem $i): bool => in_array($producer, (array) data_get($i->detail, 'producers', []), true))
            ->each(function (BrandEpicrisisItem $item) use ($producer): void {
                $detail = (array) $item->detail;
                $detail['producers'] = array_values(array_diff((array) ($detail['producers'] ?? []), [$producer]));
                $fill = ['detail' => $detail];
                if ($detail['producers'] === [] && ! $item->locked && in_array($item->status, [BrandEpicrisisItem::ACTIVE, BrandEpicrisisItem::CONFLICT, BrandEpicrisisItem::EXPIRED, BrandEpicrisisItem::OPEN], true)) {
                    $fill['status'] = BrandEpicrisisItem::DROPPED;
                }
                $item->forceFill($fill)->save();
            });

        return $seen;
    }

    /**
     * Rules over the brand's facts: two sources agreeing (or the operator) = verified; a field with one true value per
     * scope showing two values = conflict (the operator's value wins outright); a line past its date = expired.
     */
    public function reconcile(Brand $brand): void
    {
        $facts = BrandEpicrisisItem::query()->where('brand_id', $brand->id)->where('layer', BrandEpicrisisItem::FACT)
            ->whereIn('status', [BrandEpicrisisItem::ACTIVE, BrandEpicrisisItem::CONFLICT, BrandEpicrisisItem::EXPIRED])->orderBy('id')->get();
        $sources = [];
        foreach ($facts as $fact) {
            $key = $fact->field.'|'.self::norm($fact->field, (string) $fact->value);
            $sources[$key] = array_unique([...($sources[$key] ?? []), ...(array) data_get($fact->detail, 'sources', [$fact->source_kind])]);
        }
        foreach ($facts as $fact) {
            $key = $fact->field.'|'.self::norm($fact->field, (string) $fact->value);
            $verified = $fact->locked || in_array($fact->source_kind, self::OPERATOR_SOURCES, true) || count($sources[$key] ?? []) >= 2 || $fact->confidence === BrandEpicrisisItem::VERIFIED;
            $status = $fact->expires_at !== null && $fact->expires_at->isPast() ? BrandEpicrisisItem::EXPIRED : ($fact->status === BrandEpicrisisItem::EXPIRED ? BrandEpicrisisItem::ACTIVE : $fact->status);
            $fact->forceFill(['confidence' => $verified ? BrandEpicrisisItem::VERIFIED : $fact->confidence, 'status' => $status])->save();
        }
        $single = collect((array) config('moxdop-epicrisis.fields'))->filter(fn (array $f): bool => (bool) $f['single'])->keys()->all();
        $facts->whereIn('field', $single)->whereIn('status', [BrandEpicrisisItem::ACTIVE, BrandEpicrisisItem::CONFLICT])
            ->groupBy(fn (BrandEpicrisisItem $i): string => $i->field.'|'.SeoText::fold((string) $i->scope))
            ->each(function (Collection $group): void {
                $operator = $group->first(fn (BrandEpicrisisItem $i): bool => $i->locked || in_array($i->source_kind, self::OPERATOR_SOURCES, true));
                if ($operator !== null) {
                    $group->each(function (BrandEpicrisisItem $i) use ($operator): void {
                        if ($i->id !== $operator->id && self::norm($i->field, (string) $i->value) !== self::norm($operator->field, (string) $operator->value)) {
                            $i->forceFill(['status' => BrandEpicrisisItem::DROPPED, 'detail' => array_merge((array) $i->detail, ['superseded' => (int) $operator->id])])->save();
                        } elseif ($i->status === BrandEpicrisisItem::CONFLICT) {
                            $i->forceFill(['status' => BrandEpicrisisItem::ACTIVE])->save();
                        }
                    });

                    return;
                }
                $distinct = $group->map(fn (BrandEpicrisisItem $i): string => self::norm($i->field, (string) $i->value))->unique()->count();
                $group->each(fn (BrandEpicrisisItem $i) => $i->status !== ($distinct > 1 ? BrandEpicrisisItem::CONFLICT : BrandEpicrisisItem::ACTIVE)
                    ? $i->forceFill(['status' => $distinct > 1 ? BrandEpicrisisItem::CONFLICT : BrandEpicrisisItem::ACTIVE])->save() : null);
            });
    }

    /**
     * The operator's (or chat's) own line: verified and locked, it wins over every other source of its field. A line it
     * replaces is set aside so the rules do not bring it back.
     */
    public function put(Brand $brand, string $layer, string $field, string $value, ?string $scope, string $source, ?int $userId = null, ?BrandEpicrisisItem $replaces = null): BrandEpicrisisItem
    {
        $value = trim($value);
        $scope = $scope !== null && trim($scope) !== '' ? mb_substr(trim($scope), 0, 160) : null;
        $item = BrandEpicrisisItem::query()->firstOrNew(['brand_id' => $brand->id, 'fingerprint' => self::fingerprint($layer, $field, $scope, $value)]);
        $detail = (array) ($item->detail ?? []);
        unset($detail['superseded']);
        $detail['producers'] = array_values(array_unique([...(array) ($detail['producers'] ?? []), 'operator']));
        $detail['sources'] = array_values(array_unique([...(array) ($detail['sources'] ?? []), $source]));
        if ($replaces !== null && data_get($replaces->detail, 'answer') !== null && ! isset($detail['answer'])) {
            $detail['answer'] = data_get($replaces->detail, 'answer');
        }
        $days = (int) (config('moxdop-epicrisis.fields.'.$field.'.days') ?? 365);
        $item->forceFill(['layer' => $layer, 'field' => $field, 'scope' => $scope, 'value' => mb_substr($value, 0, 2000), 'detail' => $detail, 'source_kind' => $source,
            'source_ref' => $source === 'chat' ? 'Sohbet' : 'Epikriz sekmesi', 'source_text' => null, 'confidence' => BrandEpicrisisItem::VERIFIED,
            'status' => BrandEpicrisisItem::ACTIVE, 'locked' => true, 'answered_by' => $userId, 'seen_at' => now(), 'expires_at' => now()->addDays($days)])->save();
        if ($replaces !== null && $replaces->id !== $item->id) {
            $this->setAside($replaces, (int) $item->id);
        }

        return $item;
    }

    /** The operator says a line is wrong: it is no longer used and the rules do not bring it back. */
    public function setAside(BrandEpicrisisItem $item, int|string $by = 'operator'): void
    {
        $item->forceFill(['status' => BrandEpicrisisItem::DROPPED, 'locked' => false, 'detail' => array_merge((array) $item->detail, ['superseded' => $by])])->save();
    }

    public static function fingerprint(string $layer, string $field, ?string $scope, string $value): string
    {
        return hash('sha256', $layer.'|'.$field.'|'.SeoText::fold((string) $scope).'|'.self::norm($field, $value));
    }

    /** A value as compared: phones by their digits, e-mails lower-case, the rest folded. */
    public static function norm(string $field, string $value): string
    {
        return match ($field) {
            'phone' => self::phone($value),
            'email' => mb_strtolower(trim($value)),
            default => trim((string) preg_replace('/\s+/u', ' ', SeoText::fold($value))),
        };
    }

    /** A Turkish phone as 90 + ten digits ("0232 123 45 67", "+90 232 1234567" → "902321234567"); others as their digits. */
    public static function phone(string $value): string
    {
        $digits = (string) preg_replace('/\D/', '', $value);

        return match (true) {
            strlen($digits) === 12 && str_starts_with($digits, '90') => $digits,
            strlen($digits) === 11 && str_starts_with($digits, '0') => '9'.$digits,
            strlen($digits) === 10 => '90'.$digits,
            default => $digits,
        };
    }
}
