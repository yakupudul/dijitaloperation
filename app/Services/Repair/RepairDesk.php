<?php

namespace App\Services\Repair;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Gbp\Desk\ProfileFields;
use App\Services\Gbp\Desk\ProfileInfo;
use App\Services\GoogleAds\GoogleAdsSuggestions;
use App\Services\Site\ChangeApplier;
use App\Services\Site\ClusterOverlaps;
use App\Services\Site\ImageAlts;
use App\Services\Site\SiteSuggestionTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Onarım masası (Onarım Faz 2, yakup 2026-10-08): every prepared fix of every brand in one list, each with the value it
 * will write (eski → yeni) and a risk. The operator approves one row or many; approval writes through the existing
 * Admin-approved, logged and undoable paths (WordPress fixes and content draft, SEO-plugin 301, Google Ads shared
 * negative list, Business Profile description and profile facts — ADR-080). High-risk rows (page text) are approved one by one only.
 *
 * Preparation is automatic: website suggestions without a prepared value are queued nightly for "AI ile yap"
 * (RepairPreparer); the other kinds arrive prepared from their generators.
 */
final class RepairDesk
{
    public const string SITE_FIELDS = 'site_fields';

    public const string SITE_CONTENT = 'site_content';

    public const string IMAGE_ALT = 'image_alt';

    public const string REDIRECT = 'redirect';

    public const string ADS_NEGATIVE = 'ads_negative';

    public const string GBP_DESCRIPTION = 'gbp_description';

    public const string GBP_FIELDS = 'gbp_fields';

    public const array KINDS = [
        self::SITE_FIELDS => 'Başlık, açıklama, iç link, schema',
        self::SITE_CONTENT => 'Sayfa metni',
        self::IMAGE_ALT => 'Görsel alt metni',
        self::REDIRECT => '301 birleştirme',
        self::ADS_NEGATIVE => 'Google Ads negatif kelime',
        self::GBP_DESCRIPTION => 'İşletme Profili açıklaması',
        self::GBP_FIELDS => 'İşletme Profili bilgileri',
    ];

    public const string LOW = 'low';

    public const string MEDIUM = 'medium';

    public const string HIGH = 'high';

    public const array RISKS = [self::LOW => 'Düşük', self::MEDIUM => 'Orta', self::HIGH => 'Yüksek (tek tek)'];

    /** Rows read per kind (the list is a work queue, not an archive). */
    public const int LIMIT = 1500;

    /**
     * @return Collection<int, array{id: int, brand_id: int, brand: string, channel: string, kind: string, risk: string, title: string, reason: string, target: string, before: list<string>, after: list<string>, editable: list<string>, asset_id: ?int}>
     */
    public function rows(?int $brandId = null, ?string $kind = null, ?string $risk = null): Collection
    {
        $brands = Brand::query()->operational()->pluck('name', 'id');
        $rows = $this->candidates($brandId)->map(fn (Suggestion $s): ?array => $this->row($s, (string) ($brands[$s->brand_id] ?? '')))->filter()->values();

        return $rows->filter(fn (array $r): bool => ($kind === null || $r['kind'] === $kind) && ($risk === null || $r['risk'] === $risk))
            ->sortBy([['risk', 'asc'], ['brand', 'asc'], ['id', 'asc']])->values();
    }

    /** @return array{total: int, kinds: array<string, int>, brands: array<int, int>} */
    public function counts(): array
    {
        $rows = $this->rows();

        return ['total' => $rows->count(), 'kinds' => $rows->countBy('kind')->all(), 'brands' => $rows->countBy('brand_id')->all()];
    }

    /**
     * Onayla: writes each selected row through its own approved path. With more than one row, high-risk rows are left
     * for a single approval.
     *
     * @param  list<int>  $ids
     * @return array{applied: int, skipped_high: int, failed: list<string>}
     */
    public function approve(array $ids, User $user): array
    {
        $rows = $this->rows()->whereIn('id', array_map('intval', $ids))->values();
        $result = ['applied' => 0, 'skipped_high' => 0, 'failed' => []];
        if ($rows->count() > 1) {
            $result['skipped_high'] = $rows->where('risk', self::HIGH)->count();
            $rows = $rows->where('risk', '!=', self::HIGH)->values();
        }
        $suggestions = Suggestion::query()->whereIn('id', $rows->pluck('id'))->get()->keyBy('id');
        $fail = function (array $row, Throwable $error) use (&$result): void {
            $message = $error instanceof ValidationException ? collect($error->errors())->flatten()->first() : $error->getMessage();
            $result['failed'][] = $row['brand'].' · '.$row['title'].': '.$message;
            if (! $error instanceof ValidationException) {
                report($error);
            }
        };

        foreach ($rows->groupBy('kind') as $kind => $group) {
            if ($kind === self::REDIRECT) {
                try {
                    $result['applied'] += app(ClusterOverlaps::class)->redirectMany($group->map(fn (array $r): Suggestion => $suggestions[$r['id']])->values(), $user);
                } catch (Throwable $error) {
                    $fail($group->first(), $error);
                }

                continue;
            }
            if ($kind === self::ADS_NEGATIVE) {
                foreach ($group->groupBy('asset_id') as $assetId => $negatives) {
                    try {
                        $asset = DigitalAsset::query()->findOrFail((int) $assetId);
                        app(GoogleAdsSuggestions::class)->sendNegatives($asset, $user, $negatives->pluck('id')->all(), app(ExternalWriteService::class));
                        $result['applied'] += $negatives->count();
                    } catch (Throwable $error) {
                        $fail($negatives->first(), $error);
                    }
                }

                continue;
            }
            foreach ($group as $row) {
                $suggestion = $suggestions[$row['id']];
                try {
                    match ($kind) {
                        self::SITE_FIELDS, self::SITE_CONTENT => app(ChangeApplier::class)->approve($suggestion, $user),
                        self::IMAGE_ALT => app(ImageAlts::class)->approve($suggestion, $user),
                        self::GBP_DESCRIPTION => app(ProfileFields::class)->sendDescription($user, DigitalAsset::query()->findOrFail((int) $suggestion->target_id),
                            (string) data_get($suggestion->action, 'proposed'), $suggestion),
                        self::GBP_FIELDS => app(ProfileInfo::class)->send($user, $suggestion),
                    };
                    $result['applied']++;
                } catch (Throwable $error) {
                    $fail($row, $error);
                }
            }
        }

        return $result;
    }

    /**
     * Reddet: the rows leave the desk and their generators do not bring them back unless the evidence changes.
     *
     * @param  list<int>  $ids
     */
    public function reject(array $ids, User $user, ?string $reason = null): int
    {
        $ids = $this->rows()->whereIn('id', array_map('intval', $ids))->pluck('id')->all();

        return Suggestion::query()->whereIn('id', $ids)->update([
            'status' => Suggestion::DISMISSED, 'resolved_by' => $user->id, 'resolved_at' => now(),
            'operator_note' => $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 500) : null,
        ]);
    }

    /**
     * Düzelt: the operator changes the prepared value before approving it.
     */
    public function edit(int $id, string $field, string $value): void
    {
        $row = $this->rows()->firstWhere('id', $id);
        if ($row === null || ! in_array($field, $row['editable'], true)) {
            throw ValidationException::withMessages(['edit' => 'Bu değer düzenlenemez.']);
        }
        $value = trim($value);
        if ($value === '') {
            throw ValidationException::withMessages(['edit' => 'Boş bırakılamaz.']);
        }
        $suggestion = Suggestion::query()->findOrFail($id);
        $action = (array) $suggestion->action;
        if ($row['kind'] === self::GBP_DESCRIPTION) {
            $action['proposed'] = mb_substr($value, 0, 750);
        } else {
            data_set($action, 'proposal.new.'.$field, $value);
        }
        $action['edited_by_operator'] = true;
        $suggestion->forceFill(['action' => $action])->save();
    }

    /** @return Collection<int, Suggestion> */
    private function candidates(?int $brandId): Collection
    {
        $base = fn (): Builder => Suggestion::query()->whereHas('brand', fn (Builder $b): Builder => $b->operational())
            ->when($brandId !== null, fn (Builder $q): Builder => $q->where('brand_id', $brandId))
            ->whereIn('status', [Suggestion::OPEN, Suggestion::RECHECK, Suggestion::APPROVED, Suggestion::SNOOZED]);

        return collect([
            $base()->with('page:id,url,website_asset_id')->where('channel', 'search')->whereIn('action_type', SiteSuggestionTypes::APPLICABLE)->where('status', '!=', Suggestion::APPROVED),
            $base()->where('action_type', ImageAlts::TYPE),
            $base()->where('action_type', ClusterOverlaps::TYPE),
            $base()->where('channel', GoogleAdsSuggestions::CHANNEL)->where('action_type', 'ads_negative'),
            $base()->where('action_type', 'gbp_description')->where('status', '!=', Suggestion::APPROVED),
            $base()->where('action_type', ProfileInfo::TYPE)->where('status', '!=', Suggestion::APPROVED),
        ])->flatMap(fn (Builder $query): Collection => $query->orderBy('priority')->orderBy('id')->limit(self::LIMIT)->get())
            ->unique('id')->values();
    }

    /** @return array<string, mixed>|null */
    private function row(Suggestion $s, string $brand): ?array
    {
        $action = (array) $s->action;
        $base = ['id' => (int) $s->id, 'brand_id' => (int) $s->brand_id, 'brand' => $brand, 'channel' => (string) $s->channel,
            'title' => (string) $s->title,
            'reason' => trim((string) $s->reason.(filled($action['last_write_error'] ?? null) ? ' · Önceki gönderim başarısız: '.$action['last_write_error'] : ''), ' ·')];
        if ($s->status === Suggestion::SNOOZED && $s->snoozed_until !== null && $s->snoozed_until->isFuture()) {
            return null;
        }

        $row = match ((string) $s->action_type) {
            ImageAlts::TYPE => $this->altRow($base, $action),
            ClusterOverlaps::TYPE => ClusterOverlaps::mergeable($s) ? $base + ['kind' => self::REDIRECT, 'risk' => self::MEDIUM,
                'target' => (string) ($action['overlap_url'] ?? ''), 'before' => [(string) ($action['overlap_url'] ?? '')],
                'after' => ['301 → '.($action['main_url'] ?? ''), 'Eski sayfa taslağa alınır'], 'asset_id' => isset($action['site_id']) ? (int) $action['site_id'] : null] : null,
            'ads_negative' => ($action['scope'] ?? '') === 'shared' && ! isset($action['sending_write_id']) && filled($action['text'] ?? null)
                ? $base + ['kind' => self::ADS_NEGATIVE, 'risk' => self::LOW, 'target' => 'Paylaşılan negatif listesi', 'before' => [],
                    'after' => [sprintf('"%s" (%s)%s', $action['text'], $action['match_type'] ?? 'phrase', isset($action['cost']) ? ' · boşa giden '.$action['cost'] : '')]] : null,
            'gbp_description' => filled($action['proposed'] ?? null) ? $base + ['kind' => self::GBP_DESCRIPTION, 'risk' => self::LOW, 'target' => 'İşletme açıklaması',
                'before' => [(string) ($action['current'] ?? '—')], 'after' => [(string) $action['proposed']], 'editable' => ['description']] : null,
            ProfileInfo::TYPE => filled($action['fields'] ?? null) ? $base + ['kind' => self::GBP_FIELDS, 'risk' => self::MEDIUM,
                'target' => trim(($action['location'] ?? '').' · '.(ProfileInfo::FIELD_LABELS[$action['field'] ?? ''] ?? 'Profil bilgisi'), ' ·'), 'before' => [(string) ($action['current'] ?? '') ?: '—'],
                'after' => [(string) ($action['proposed'] ?? '')]] : null,
            default => $this->siteRow($s, $base, $action),
        };

        return $row === null ? null : $row + ['editable' => [], 'asset_id' => $s->target_id !== null ? (int) $s->target_id : null];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>|null
     */
    private function altRow(array $base, array $action): ?array
    {
        $images = (array) ($action['images'] ?? []);
        if ($images === [] || isset($action['writes'])) {
            return null;
        }

        return $base + ['kind' => self::IMAGE_ALT, 'risk' => self::LOW, 'target' => count($images).' görsel', 'before' => [],
            'after' => array_map(fn (array $i): string => ($i['file'] ?? '#'.$i['image_id']).' → '.$i['alt'], array_slice($images, 0, 8)),
            'asset_id' => isset($action['site_id']) ? (int) $action['site_id'] : null];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>|null
     */
    private function siteRow(Suggestion $s, array $base, array $action): ?array
    {
        $proposal = (array) ($action['proposal'] ?? []);
        if ($proposal === [] || isset($action['proposal_blocked']) || isset($action['writes'])) {
            return null;
        }
        $current = (array) ($proposal['current'] ?? []);
        $new = (array) ($proposal['new'] ?? []);
        $before = [];
        $after = [];
        $editable = [];
        foreach (['seo_title' => 'Başlık', 'meta_description' => 'Açıklama'] as $field => $label) {
            if (filled($new[$field] ?? null)) {
                $before[] = $label.': '.((string) ($current[$field] ?? '') ?: '—');
                $after[] = $label.': '.$new[$field];
                $editable[] = $field;
            }
        }
        if (($links = count((array) ($new['internal_links'] ?? []))) > 0) {
            $after[] = $links.' iç link eklenir';
        }
        if (filled($new['schema_json'] ?? null)) {
            $after[] = 'Yapılandırılmış veri (schema) eklenir';
        }
        $html = filled($new['html'] ?? null);
        if ($html) {
            $after[] = 'Sayfa metni güncellenir (WordPress taslağı, sonra "Canlıya al")';
        }
        if ($after === []) {
            return null;
        }

        return $base + ['kind' => $html ? self::SITE_CONTENT : self::SITE_FIELDS,
            'risk' => $html ? self::HIGH : (filled($new['schema_json'] ?? null) ? self::MEDIUM : self::LOW),
            'target' => (string) ($s->page?->url ?? ''), 'before' => $before, 'after' => $after, 'editable' => $editable,
            'asset_id' => $s->page?->website_asset_id !== null ? (int) $s->page->website_asset_id : null];
    }
}
