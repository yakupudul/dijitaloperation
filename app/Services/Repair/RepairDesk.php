<?php

namespace App\Services\Repair;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Brand\BrandDataAudit;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\ExternalWrites\GoogleAdsChangeWriter;
use App\Services\Gbp\Desk\ProfileFields;
use App\Services\Gbp\Desk\ProfileInfo;
use App\Services\GoogleAds\GoogleAdsChanges;
use App\Services\GoogleAds\GoogleAdsSuggestions;
use App\Services\Site\ChangeApplier;
use App\Services\Site\ClusterOverlaps;
use App\Services\Site\ImageAlts;
use App\Services\Site\SiteSuggestionTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Onarım masası (Onarım Faz 2, yakup 2026-10-08): every prepared fix of every brand in one list, each with the value it
 * will write (eski → yeni) and a risk. The operator approves one row or many; approval writes through the existing
 * Admin-approved, logged and undoable paths (WordPress fixes and content draft, SEO-plugin 301, Google Ads shared
 * negative list and setting changes — ADR-081, Business Profile description and profile facts — ADR-080). High-risk rows (page text) are approved one by one only.
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

    public const string ADS_CHANGE = 'ads_change';

    public const string WEB_FIX = 'web_fix';

    public const string WEB_TASK = 'web_task';

    public const string BRAND_DATA = 'brand_data';

    public const array KINDS = [
        self::SITE_FIELDS => 'Başlık, açıklama, iç link, schema',
        self::SITE_CONTENT => 'Sayfa metni',
        self::IMAGE_ALT => 'Görsel alt metni',
        self::REDIRECT => '301 birleştirme',
        self::ADS_NEGATIVE => 'Google Ads negatif kelime',
        self::ADS_CHANGE => 'Google Ads ayarı',
        self::GBP_DESCRIPTION => 'İşletme Profili açıklaması',
        self::GBP_FIELDS => 'İşletme Profili bilgileri',
        self::WEB_FIX => 'Site teknik düzeltmesi',
        self::WEB_TASK => 'Senin yapacağın (sitede elle)',
        self::BRAND_DATA => 'Marka bilgisi (yer ve hizmet)',
    ];

    public const string LOW = 'low';

    public const string MEDIUM = 'medium';

    public const string HIGH = 'high';

    /** A task only the operator can do on the site or the hosting: "Yaptım" hides it until the nightly check. */
    public const string MANUAL = 'manual';

    public const array RISKS = [self::LOW => 'Düşük', self::MEDIUM => 'Orta', self::HIGH => 'Yüksek (tek tek)', self::MANUAL => 'Elle yapılacak'];

    public const string LANE_READY = 'ready';

    public const string LANE_REVIEW = 'review';

    public const string LANE_MANUAL = 'manual';

    /** Şeritler (yakup 2026-10-09): ready to approve, worth a look, only the operator can do it. */
    public const array LANES = [self::LANE_READY => 'Onayla, bitsin', self::LANE_REVIEW => 'Bir göz at', self::LANE_MANUAL => 'Senin elin gerekiyor'];

    /** Days the "after approval" strip and the brand health bar look back. */
    public const int TRACK_DAYS = 14;

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

    /** @param  array{risk: string}  $row */
    public static function lane(array $row): string
    {
        return match ($row['risk']) {
            self::LOW => self::LANE_READY,
            self::MANUAL => self::LANE_MANUAL,
            default => self::LANE_REVIEW,
        };
    }

    /**
     * İş paketleri: rows of one brand, one kind and one lane decided together (323 merges are one decision, not 323).
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array{key: string, brand_id: int, brand: string, kind: string, lane: string, count: int, high: int, ids: list<int>, sample: list<array<string, mixed>>}>
     */
    public function packages(Collection $rows): Collection
    {
        return $rows->groupBy(fn (array $r): string => self::packageKey($r))
            ->map(function (Collection $group, string $key): array {
                $first = $group->first();

                return ['key' => $key, 'brand_id' => $first['brand_id'], 'brand' => $first['brand'], 'kind' => $first['kind'], 'lane' => self::lane($first),
                    'count' => $group->count(), 'high' => $group->where('risk', self::HIGH)->count(), 'ids' => $group->pluck('id')->all(),
                    'sample' => $group->take(3)->values()->all()];
            })
            ->sortBy([['count', 'desc'], ['brand', 'asc'], ['kind', 'asc']])->values();
    }

    /** @param  array{brand_id: int, kind: string, risk: string}  $row */
    public static function packageKey(array $row): string
    {
        return $row['brand_id'].'.'.$row['kind'].'.'.self::lane($row);
    }

    /**
     * Onaydan sonra: what happened to the fixes approved in the last two weeks (Onaylandı → Siteye yazıldı → Doğrulandı),
     * and how many came back to the desk because the write failed or was undone.
     *
     * @return array{queued: int, written: int, verified: int, returned: int}
     */
    public function pipeline(?int $brandId = null): array
    {
        $since = now()->subDays(self::TRACK_DAYS);
        $latest = ExternalWriteAction::query()->whereNotNull('suggestion_id')->where('created_at', '>=', $since)
            ->when($brandId !== null, fn (Builder $q): Builder => $q->where('brand_id', $brandId))
            ->orderBy('id')->get(['suggestion_id', 'status'])->keyBy('suggestion_id');
        $verified = Suggestion::query()->whereIn('id', $latest->keys())
            ->whereIn('verification', [Suggestion::VERIFY_CONFIRMED, Suggestion::VERIFY_AUTO])->pluck('id')->flip();
        $result = ['queued' => 0, 'written' => 0, 'verified' => 0, 'returned' => 0];
        foreach ($latest as $suggestionId => $write) {
            $stage = match (true) {
                $verified->has($suggestionId) => 'verified',
                in_array($write->status, ['succeeded', 'partial', 'undo_failed'], true) => 'written',
                in_array($write->status, ['failed', 'undone', 'rejected', 'cancelled'], true) => 'returned',
                default => 'queued',
            };
            $result[$stage]++;
        }

        return $result;
    }

    /**
     * Marka sağlığı: per brand the work left before it is perfect and what was done in the last two weeks.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array{brand_id: int, brand: string, open: int, done: int, percent: int}>
     */
    public function health(Collection $rows): Collection
    {
        $done = Suggestion::query()->where('status', Suggestion::APPLIED)->where('applied_at', '>=', now()->subDays(self::TRACK_DAYS))
            ->whereIn('brand_id', $rows->pluck('brand_id')->unique())->selectRaw('brand_id, count(*) as aggregate')->groupBy('brand_id')->pluck('aggregate', 'brand_id');

        return $rows->groupBy('brand_id')->map(function (Collection $group, int $brandId) use ($done): array {
            $finished = (int) ($done[$brandId] ?? 0);

            return ['brand_id' => $brandId, 'brand' => (string) $group->first()['brand'], 'open' => $group->count(), 'done' => $finished,
                'percent' => (int) round(100 * $finished / max(1, $finished + $group->count()))];
        })->sortByDesc('open')->values();
    }

    public const array WRITE_LABELS = [
        ExternalWriteAction::ACTION_SITE_FIX => 'Site düzeltmesi', ExternalWriteAction::ACTION_ADS_CHANGE => 'Google Ads ayarı',
        ExternalWriteAction::ACTION_NEGATIVE_LIST_ADD => 'Google Ads negatif kelime', ExternalWriteAction::ACTION_PROFILE_FIELDS => 'İşletme Profili bilgisi',
        ExternalWriteAction::ACTION_PROFILE_UPDATE => 'İşletme Profili açıklaması', ExternalWriteAction::ACTION_CONTENT_DRAFT => 'Sayfa metni taslağı',
        ExternalWriteAction::ACTION_CONTENT_APPLY => 'Sayfa metni canlıya', ExternalWriteAction::ACTION_CONNECTOR_UPDATE => 'Eklenti güncellemesi',
        ExternalWriteAction::ACTION_MEDIA_UPLOAD => 'İşletme Profili fotoğrafı', ExternalWriteAction::ACTION_LOCAL_POST => 'İşletme Profili gönderisi',
        ExternalWriteAction::ACTION_REVIEW_REPLY => 'Yorum yanıtı', ExternalWriteAction::ACTION_ARTICLE_DRAFTS => 'Yazı taslağı',
        ExternalWriteAction::ACTION_DRAFT_CREATE => 'Yazı taslağı', ExternalWriteAction::ACTION_SITE_BUILD => 'Site kurulumu', ExternalWriteAction::ACTION_UPDATE_APPLY => 'WordPress güncellemesi',
    ];

    public const array CHANGE_LABELS = [
        'seo_title' => 'başlık', 'seo_description' => 'açıklama', 'canonical' => 'asıl adres (canonical)', 'noindex' => 'dizin ayarı',
        'alt_text' => 'görsel alt metni', 'schema' => 'yapılandırılmış veri', 'redirect' => '301 yönlendirme', 'merge_redirect' => '301 birleştirme',
        'internal_link' => 'iç link', 'llms_txt' => 'llms.txt', 'content' => 'sayfa metni',
    ];

    /** The site's short English reasons, in words the operator can act on. */
    public const array PLAIN_ERRORS = [
        'anchor text not found as plain text in the page' => 'bağlantı metni sayfada düz yazı olarak yok (başlıkta, butonda ya da zaten linkli), link eklenmedi',
        'the page already links to this URL' => 'sayfa bu adrese zaten link veriyor',
        'changed_since' => 'değer MoxDOP\'tan sonra sitede değiştirilmiş, üzerine yazılmadı',
        'invalid target' => 'sayfa sitede bulunamadı (silinmiş ya da taşınmış olabilir)',
        'a page cannot redirect to itself' => 'sayfa kendisine yönlendirilemez',
        'SEO fixes are disabled on this site.' => 'eklenti ayarlarında "SEO fixes" kapalı',
    ];

    public static function plainError(string $error): string
    {
        $error = trim($error);

        return self::PLAIN_ERRORS[$error] ?? WebHealthAudit::plainError($error);
    }

    /**
     * Yapılanlar: the approved writes of the last days, grouped per day, asset and kind, each with one sentence saying
     * what changed (counts per change type), what could not be done and why, and the single writes for undo.
     *
     * @return Collection<int, array{key: string, at: string, asset: string, label: string, sentence: string, problems: list<string>, ok: int, partial: int, failed: int, pending: int, items: list<array{id: int, title: string, status: string, error: string, undoable: bool}>}>
     */
    public function done(?int $brandId = null, int $days = 7): Collection
    {
        $writes = ExternalWriteAction::query()->with('digitalAsset:id,name')->whereNotNull('suggestion_id')
            ->when($brandId !== null, fn (Builder $q): Builder => $q->where('brand_id', $brandId))
            ->where('created_at', '>=', now()->subDays($days))->latest('id')->limit(1000)->get();
        $suggestions = Suggestion::query()->with('page:id,url')->whereIn('id', $writes->pluck('suggestion_id')->unique())->get(['id', 'title', 'page_id'])->keyBy('id');

        return $writes->groupBy(fn (ExternalWriteAction $w): string => $w->created_at?->timezone('Europe/Istanbul')->format('Y-m-d').'|'.$w->digital_asset_id.'|'.$w->action)
            ->map(function (Collection $group, string $key) use ($suggestions): array {
                $first = $group->first();
                $done = [];
                $missed = [];
                foreach ($group as $write) {
                    $results = collect((array) data_get($write->result, 'changes', []))->keyBy('reference');
                    foreach ((array) data_get($write->request_payload, 'changes', []) as $change) {
                        $type = self::CHANGE_LABELS[$change['type'] ?? ''] ?? null;
                        if ($type === null) {
                            continue;
                        }
                        $result = (array) $results->get($change['reference'] ?? '', []);
                        if (in_array($write->status, ['succeeded', 'partial', 'undo_failed'], true) && ($result === [] || (bool) ($result['ok'] ?? false))) {
                            $done[$type] = ($done[$type] ?? 0) + 1;
                        } elseif (in_array($write->status, ['partial', 'failed'], true)) {
                            $reason = self::plainError((string) ($result['error'] ?? $write->error ?? ''));
                            $missed[$type.': '.$reason] = ($missed[$type.': '.$reason] ?? 0) + 1;
                        }
                    }
                }
                $statuses = $group->countBy(fn (ExternalWriteAction $w): string => match ($w->status) {
                    'succeeded', 'undo_failed' => 'ok', 'partial' => 'partial', 'failed', 'rejected', 'cancelled' => 'failed', 'undone', 'undoing' => 'undone', default => 'pending',
                });
                $label = self::WRITE_LABELS[$first->action] ?? $first->action;
                $sentence = $done !== []
                    ? collect($done)->map(fn (int $n, string $type): string => $n.' '.$type)->implode(', ').' yazıldı'
                    : (($statuses->get('ok', 0) + $statuses->get('partial', 0)) > 0 ? ($statuses->get('ok', 0) + $statuses->get('partial', 0)).' iş yapıldı' : '');
                if (($statuses['pending'] ?? 0) > 0) {
                    $sentence = trim($sentence.($sentence !== '' ? '; ' : '').$statuses['pending'].' iş sırada');
                }
                if (($statuses['undone'] ?? 0) > 0) {
                    $sentence = trim($sentence.($sentence !== '' ? '; ' : '').$statuses['undone'].' iş geri alındı');
                }
                $problems = collect($missed)->map(fn (int $n, string $text): string => $n.' '.$text)->values()->all();
                foreach ($group->where('status', 'failed') as $write) {
                    if ((array) data_get($write->request_payload, 'changes', []) === [] && filled($write->error)) {
                        $problems[] = self::plainError((string) $write->error);
                    }
                }

                return ['key' => $key, 'at' => (string) $first->created_at?->timezone('Europe/Istanbul')->format('d.m H:i'), 'asset' => (string) ($first->digitalAsset?->name ?? ''),
                    'label' => $label, 'sentence' => $sentence !== '' ? $sentence : 'Yazılamadı', 'problems' => array_values(array_unique($problems)),
                    'ok' => (int) ($statuses['ok'] ?? 0), 'partial' => (int) ($statuses['partial'] ?? 0), 'failed' => (int) ($statuses['failed'] ?? 0), 'pending' => (int) ($statuses['pending'] ?? 0),
                    'items' => $group->map(fn (ExternalWriteAction $w): array => ['id' => (int) $w->id, 'title' => (string) ($suggestions[$w->suggestion_id]?->title ?? $w->action),
                        'page' => (string) ($suggestions[$w->suggestion_id]?->page?->url ?? ''), 'lines' => self::writeLines($w),
                        'status' => $w->statusLabel(), 'error' => filled($w->error) && (array) data_get($w->request_payload, 'changes', []) === [] ? self::plainError((string) $w->error) : '',
                        'undoable' => $w->isUndoable()])->values()->all()];
            })->values();
    }

    /**
     * What one write changed, a line per change: "başlık: Yeni değer" (✕ and the reason when the site did not do it).
     *
     * @return list<array{text: string, ok: bool}>
     */
    public static function writeLines(ExternalWriteAction $write): array
    {
        $results = collect((array) data_get($write->result, 'changes', []))->keyBy('reference');
        $lines = [];
        foreach ((array) data_get($write->request_payload, 'changes', []) as $change) {
            $type = (string) ($change['type'] ?? '');
            $value = $change['value'] ?? null;
            $text = match ($type) {
                'redirect', 'merge_redirect' => (string) ($change['from'] ?? '').' → '.(string) $value,
                'internal_link' => '"'.data_get($value, 'anchor').'" → '.data_get($value, 'url'),
                'noindex' => $value ? 'Google\'a kapatıldı (noindex)' : 'Google\'a açıldı',
                'schema', 'content', 'llms_txt' => 'güncellendi',
                default => is_scalar($value) ? (string) $value : '',
            };
            $result = (array) $results->get($change['reference'] ?? '', []);
            $ok = $result === [] ? ! in_array($write->status, ['failed', 'rejected', 'cancelled'], true) : (bool) ($result['ok'] ?? false);
            $line = (self::CHANGE_LABELS[$type] ?? $type).': '.Str::limit($text, 160);
            $lines[] = ['text' => $ok ? $line : $line.' — yapılmadı: '.self::plainError((string) ($result['error'] ?? $write->error ?? '')), 'ok' => $ok];
        }

        return $lines;
    }

    /**
     * Word-level difference of one "Şimdi" and one "Onaylanınca" line: each word with whether it changed.
     *
     * @return array{before: list<array{0: string, 1: bool}>, after: list<array{0: string, 1: bool}>}
     */
    public static function diff(string $before, string $after): array
    {
        $a = preg_split('/(\s+)/u', $before, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $b = preg_split('/(\s+)/u', $after, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE) ?: [];
        if (count($a) * count($b) > 160000) {
            return ['before' => array_map(fn (string $w): array => [$w, true], $a), 'after' => array_map(fn (string $w): array => [$w, true], $b)];
        }
        $n = count($a);
        $m = count($b);
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }
        $keepA = [];
        $keepB = [];
        for ($i = 0, $j = 0; $i < $n && $j < $m;) {
            if ($a[$i] === $b[$j]) {
                $keepA[$i++] = true;
                $keepB[$j++] = true;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $i++;
            } else {
                $j++;
            }
        }
        $mark = fn (array $words, array $keep): array => array_map(fn (string $w, int $k): array => [$w, ! isset($keep[$k]) && trim($w) !== ''], $words, array_keys($words));

        return ['before' => $mark($a, $keepA), 'after' => $mark($b, $keepB)];
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
                        self::ADS_CHANGE => app(GoogleAdsChanges::class)->send($user, $suggestion),
                        self::WEB_FIX => app(WebHealthAudit::class)->send($user, $suggestion),
                        self::WEB_TASK => app(WebHealthAudit::class)->markDone($user, $suggestion),
                        self::BRAND_DATA => app(BrandDataAudit::class)->apply($user, $suggestion),
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
            // A fill-the-gap row the nightly pass no longer proposes (recheck) is stale: the profile may have the value now.
            $base()->where('action_type', 'gbp_description')->whereNotIn('status', [Suggestion::APPROVED, Suggestion::RECHECK]),
            $base()->where('action_type', ProfileInfo::TYPE)->whereNotIn('status', [Suggestion::APPROVED, Suggestion::RECHECK]),
            $base()->where('channel', GoogleAdsSuggestions::CHANNEL)->where('action_type', GoogleAdsChanges::TYPE)->where('status', '!=', Suggestion::APPROVED),
            $base()->where('action_type', WebHealthAudit::TYPE)->where('status', '!=', Suggestion::APPROVED),
            $base()->where('action_type', BrandDataAudit::TYPE)->where('status', '!=', Suggestion::APPROVED),
        ])->flatMap(fn (Builder $query): Collection => $query->orderBy('priority')->orderBy('id')->limit(self::LIMIT)->get())
            ->unique('id')->values();
    }

    /** @return array<string, mixed>|null */
    private function row(Suggestion $s, string $brand): ?array
    {
        $action = (array) $s->action;
        $lastError = $action['last_write_error'] ?? $action['merge_error'] ?? null;
        $base = ['id' => (int) $s->id, 'brand_id' => (int) $s->brand_id, 'brand' => $brand, 'channel' => (string) $s->channel,
            'title' => (string) $s->title,
            'reason' => trim((string) $s->reason.(filled($lastError) ? ' · Önceki gönderim başarısız: '.$lastError : ''), ' ·')];
        if ($s->status === Suggestion::SNOOZED && $s->snoozed_until !== null && $s->snoozed_until->isFuture()) {
            return null;
        }

        $row = match ((string) $s->action_type) {
            ImageAlts::TYPE => $this->altRow($base, $action),
            ClusterOverlaps::TYPE => ClusterOverlaps::mergeable($s) ? $base + ['kind' => self::REDIRECT, 'risk' => self::MEDIUM,
                'target' => (string) ($action['overlap_url'] ?? ''), 'before' => [(string) ($action['overlap_url'] ?? '')],
                'after' => ['301 → '.($action['main_url'] ?? ''), 'Eski sayfa taslağa alınır'], 'asset_id' => isset($action['site_id']) ? (int) $action['site_id'] : null] : null,
            'ads_negative' => ($action['scope'] ?? '') === 'shared' && ! isset($action['sending_write_id']) && filled($action['text'] ?? null)
                ? $base + ['kind' => self::ADS_NEGATIVE, 'risk' => ($action['blocks'] ?? []) === [] ? self::LOW : self::HIGH, 'target' => 'Paylaşılan negatif listesi', 'before' => [],
                    'after' => [GoogleAdsSuggestions::line((string) $action['text'], (string) ($action['match_type'] ?? 'PHRASE')).(isset($action['cost']) ? ' · boşa giden '.$action['cost'] : '')
                        .(($action['blocks'] ?? []) !== [] ? ' · engelleyebileceği: '.implode(', ', array_slice((array) $action['blocks'], 0, 5)) : '')]] : null,
            'gbp_description' => filled($action['proposed'] ?? null) ? $base + ['kind' => self::GBP_DESCRIPTION, 'risk' => self::LOW, 'target' => 'İşletme açıklaması',
                'before' => [(string) ($action['current'] ?? '—')], 'after' => [(string) $action['proposed']], 'editable' => ['description']] : null,
            ProfileInfo::TYPE => filled($action['fields'] ?? null) ? $base + ['kind' => self::GBP_FIELDS, 'risk' => ($action['field'] ?? null) === 'regular_hours' ? self::HIGH : self::MEDIUM,
                'target' => trim(($action['location'] ?? '').' · '.(ProfileInfo::FIELD_LABELS[$action['field'] ?? ''] ?? 'Profil bilgisi'), ' ·'), 'before' => [(string) ($action['current'] ?? '') ?: '—'],
                'after' => [(string) ($action['proposed'] ?? '')]] : null,
            GoogleAdsChanges::TYPE => filled($action['field'] ?? null) ? $base + ['kind' => self::ADS_CHANGE,
                'risk' => in_array($action['risk'] ?? null, [self::LOW, self::MEDIUM], true) ? $action['risk'] : self::MEDIUM,
                'target' => (string) ($action['target'] ?? ''), 'before' => [GoogleAdsChangeWriter::text($action['before'] ?? null)],
                'after' => [(string) ($action['label'] ?? '')]] : null,
            WebHealthAudit::TYPE => $base + ['kind' => ($action['changes'] ?? []) !== [] ? self::WEB_FIX : self::WEB_TASK,
                'risk' => ($action['changes'] ?? []) !== [] ? self::MEDIUM : self::MANUAL, 'target' => (string) ($action['target'] ?? ''),
                'before' => array_map('strval', (array) ($action['before'] ?? [])), 'after' => array_map('strval', (array) ($action['after'] ?? [])),
                'asset_id' => isset($action['site_id']) ? (int) $action['site_id'] : null],
            BrandDataAudit::TYPE => filled($action['op'] ?? null) ? $base + ['kind' => self::BRAND_DATA,
                'risk' => in_array($action['risk'] ?? null, [self::LOW, self::MEDIUM], true) ? $action['risk'] : self::MEDIUM,
                'target' => 'Marka › Ayarlar', 'before' => [(string) ($action['before'] ?? '—')], 'after' => [(string) ($action['after'] ?? '')]] : null,
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
