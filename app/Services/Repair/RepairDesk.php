<?php

namespace App\Services\Repair;

use App\Jobs\ApproveRepairRowsJob;
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
use App\Services\Operator\ExternalLinks;
use App\Services\Outcomes\OutcomeTracker;
use App\Services\Site\BrokenTranslations;
use App\Services\Site\ChangeApplier;
use App\Services\Site\ClusterOverlaps;
use App\Services\Site\ImageAlts;
use App\Services\Site\SiteSuggestionTypes;
use App\Services\Work\PageTraffic;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

use function Illuminate\Support\defer;

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

    /** Google Ads changes that are only ever approved one by one (money and reach). */
    public const array ADS_SINGLE_FIELDS = ['budget', 'keyword_status', 'location_option'];

    public const string WEB_FIX = 'web_fix';

    public const string WEB_TASK = 'web_task';

    public const string BRAND_DATA = 'brand_data';

    public const array KINDS = [
        self::SITE_FIELDS => 'Başlık, açıklama, iç link, schema',
        self::SITE_CONTENT => 'Sayfa metni',
        self::IMAGE_ALT => 'Resim açıklamaları',
        self::REDIRECT => 'Aynı konudaki sayfayı birleştirme',
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

    /**
     * Rows read per kind (the list is a work queue, not an archive). Website rows are read only when prepared, so the
     * limit is never spent on suggestions still waiting for "AI ile yap" (a 1500 cap cut Decco's 1483 rows to 874 in the
     * all-brands list while its own page showed all of them, yakup 2026-10-20).
     */
    public const int LIMIT = 5000;

    /** Cache key of summary(): the one waiting-work count of the menu, Bugün, Onarım masası and the brand pages. */
    public const string SUMMARY_KEY = 'repair-desk.summary';

    /** The desk count is counted again after a page's response once it is older than this. */
    private const int SUMMARY_FRESH_SECONDS = 120;

    /** Set on a row the operator approved while ApproveRepairRowsJob has not run yet: the row leaves the list meanwhile. */
    public const string QUEUED_AT = 'desk_queued_at';

    /** Why the last approval of a row could not be sent (shown on the row until it is approved again). */
    public const string DESK_ERROR = 'desk_error';

    /** A queued mark older than this (the job died) no longer hides the row. */
    public const int QUEUED_MINUTES = 30;

    /** @var array<string, array{won: int, neutral: int, lost: int, unclear: int, measured: int, weight: float}>|null */
    private ?array $learned = null;

    /** @var array<int, DigitalAsset|null> assets the rows' "Aç ↗" links point into (one query per asset) */
    private array $linkAssets = [];

    /** @var array<int, list<array<string, mixed>>> */
    private array $linkSets = [];

    /**
     * @return Collection<int, array{id: int, brand_id: int, brand: string, channel: string, kind: string, risk: string, title: string, priority: int, page_id: ?int, impressions?: ?int, clicks?: ?int, page_title: string, reason: string, target: string, before: list<string>, after: list<string>, editable: list<string>, asset_id: ?int, open: array<string, mixed>|null}>
     */
    public function rows(?int $brandId = null, ?string $kind = null, ?string $risk = null, bool $withQueued = false): Collection
    {
        $brands = Brand::query()->operational()->pluck('name', 'id');
        $rows = self::withTraffic($this->candidates($brandId)
            ->reject(fn (Suggestion $s): bool => ! $withQueued && self::isQueued($s))
            ->map(fn (Suggestion $s): ?array => $this->row($s, (string) ($brands[$s->brand_id] ?? '')))->filter()->values());

        return $rows->filter(fn (array $r): bool => ($kind === null || $r['kind'] === $kind) && ($risk === null || $r['risk'] === $risk))
            ->sortBy([['risk', 'asc'], ['brand', 'asc'], ['id', 'asc']])->values();
    }

    /**
     * Bekleyen iş, one number everywhere (yakup 2026-10-20, "aynı şey her ekranda başka sayı"): `decide` is what waits for
     * the operator's approval (lanes ready + review, the menu badge), `manual` what only the operator can do on the site.
     * Every screen reads it from here; approving, rejecting and editing drop it so the next screen counts again.
     *
     * @return array{decide: int, manual: int, lanes: array<string, int>, brands: array<int, array{decide: int, manual: int}>}
     */
    public function summary(): array
    {
        // Reading every desk row takes seconds; the menu of every page reads this. A page shows the last count and a
        // stale one is counted again after the response (only the very first count is made while a page waits).
        $cached = Cache::get(self::SUMMARY_KEY);
        if (is_array($cached) && is_array($cached['summary'] ?? null)) {
            if ((int) ($cached['at'] ?? 0) >= now()->getTimestamp() - self::SUMMARY_FRESH_SECONDS) {
                return $cached['summary'];
            }
            if (! app()->runningUnitTests()) {
                if (Cache::add(self::SUMMARY_KEY.'.lock', 1, 120)) {
                    defer(static function (): void {
                        try {
                            self::rememberSummary(app(self::class)->rows());
                        } finally {
                            Cache::forget(self::SUMMARY_KEY.'.lock');
                        }
                    });
                }

                return $cached['summary'];
            }
        }

        return self::rememberSummary($this->rows());
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{decide: int, manual: int, lanes: array<string, int>, brands: array<int, array{decide: int, manual: int}>}
     */
    public static function summarize(Collection $rows): array
    {
        $lanes = array_merge(array_fill_keys(array_keys(self::LANES), 0), $rows->countBy(fn (array $row): string => self::lane($row))->all());
        $brands = $rows->groupBy('brand_id')->map(fn (Collection $group): array => [
            'decide' => $group->reject(fn (array $row): bool => self::lane($row) === self::LANE_MANUAL)->count(),
            'manual' => $group->filter(fn (array $row): bool => self::lane($row) === self::LANE_MANUAL)->count(),
        ])->all();

        return ['decide' => $lanes[self::LANE_READY] + $lanes[self::LANE_REVIEW], 'manual' => $lanes[self::LANE_MANUAL], 'lanes' => $lanes, 'brands' => $brands];
    }

    /**
     * The desk read every row anyway: its count becomes the menu's and Bugün's, so the desk never disagrees with them.
     *
     * @param  Collection<int, array<string, mixed>>  $rows  rows() of all brands
     * @return array{decide: int, manual: int, lanes: array<string, int>, brands: array<int, array{decide: int, manual: int}>}
     */
    public static function rememberSummary(Collection $rows): array
    {
        $summary = self::summarize($rows);
        Cache::put(self::SUMMARY_KEY, ['at' => now()->getTimestamp(), 'summary' => $summary], now()->addDay());

        return $summary;
    }

    public static function forgetSummary(): void
    {
        // Kept as the last count but marked stale: the next page shows it and counts again after its response.
        $cached = Cache::get(self::SUMMARY_KEY);
        if (is_array($cached) && is_array($cached['summary'] ?? null) && ! app()->runningUnitTests()) {
            Cache::put(self::SUMMARY_KEY, ['at' => 0, 'summary' => $cached['summary']], now()->addDay());

            return;
        }
        Cache::forget(self::SUMMARY_KEY);
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
        return match ($row['risk'] ?? null) {
            self::LOW => self::LANE_READY,
            self::MANUAL => self::LANE_MANUAL,
            default => self::LANE_REVIEW,
        };
    }

    /**
     * İş paketleri: rows of one brand, one kind and one lane decided together (323 merges are one decision, not 323).
     * The same grouping counts and lists the open work of every screen (OpenWork passes its own key: brand + work type).
     *
     * Order = expected gain (yakup, 2026-10-11): packages whose pages Google did not show in 90 days come last; the rest
     * by the sum of their rows' gain (self::gain: priority weight × the page's Search Console reach), then by size.
     *
     * Order: package size × the learned weight of its work types (OutcomeTracker::weight(), 0.8 … 1.2, 1.0 until
     * enough results are measured), so work that kept winning 30 / 90 days after approval comes before a package of
     * about the same size whose work kept losing. `track` is that record in words ("Bu tür işler: 12 ölçümün 9'u işe
     * yaradı").
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  (callable(array<string, mixed>): string)|null  $keyOf  package key of a row (default: brand.kind.lane)
     * @return Collection<int, array{key: string, brand_id: int, brand: string, kind: string, lane: string, count: int, high: int, critical: int, impressions: int, clicks: int, measured: bool, unseen: bool, gain: float, ids: list<int>, sample: list<array<string, mixed>>, weight: float, score: float, track: string}>
     */
    public function packages(Collection $rows, ?callable $keyOf = null): Collection
    {
        $keyOf ??= fn (array $r): string => self::packageKey($r);
        $learned = $this->learned();

        return $rows->groupBy(fn (array $r): string => $keyOf($r))
            ->map(function (Collection $group, string $key) use ($learned): array {
                $first = $group->first();
                $measured = $group->filter(fn (array $r): bool => ($r['impressions'] ?? null) !== null);
                $impressions = (int) $measured->sum('impressions');
                $types = $group->pluck('work_type')->filter()->unique()->values();
                $weight = round((float) $group->avg(fn (array $r): float => (float) ($learned[$r['work_type'] ?? '']['weight'] ?? 1.0)), 3);
                $won = (int) $types->sum(fn (string $t): int => (int) ($learned[$t]['won'] ?? 0));
                $measuredResults = (int) $types->sum(fn (string $t): int => (int) ($learned[$t]['measured'] ?? 0));
                $gain = round((float) $group->sum(fn (array $r): float => self::gain($r)), 2);

                return ['key' => $key, 'brand_id' => $first['brand_id'], 'brand' => $first['brand'], 'kind' => $first['kind'], 'lane' => self::lane($first),
                    'count' => $group->count(), 'high' => $group->where('risk', self::HIGH)->count(),
                    'critical' => $group->filter(fn (array $r): bool => (int) ($r['priority'] ?? 3) <= 1)->count(),
                    'impressions' => $impressions, 'clicks' => (int) $measured->sum('clicks'), 'measured' => $measured->isNotEmpty(),
                    'unseen' => $measured->count() === $group->count() && $impressions === 0,
                    'gain' => $gain, 'ids' => $group->pluck('id')->all(),
                    'sample' => $group->sortByDesc(fn (array $r): float => self::gain($r))->take(3)->values()->all(),
                    'weight' => $weight, 'score' => round($gain * $weight, 2),
                    'track' => $measuredResults > 0 ? 'Bu tür işler: '.$measuredResults.' ölçümün '.$won.' tanesi işe yaradı' : ''];
            })
            ->sortBy([['unseen', 'asc'], ['score', 'desc'], ['count', 'desc'], ['brand', 'asc'], ['kind', 'asc']])->values();
    }

    /**
     * The learned record per work type (OutcomeTracker::workTypes(), cached), read once per desk instance.
     *
     * @return array<string, array{won: int, neutral: int, lost: int, unclear: int, measured: int, weight: float}>
     */
    public function learned(): array
    {
        return $this->learned ??= app(OutcomeTracker::class)->workTypes();
    }

    /** @param  array{brand_id: int, kind: string, risk: string}  $row */
    public static function packageKey(array $row): string
    {
        return $row['brand_id'].'.'.$row['kind'].'.'.self::lane($row);
    }

    /**
     * Adds each page-bound row's Search Console impressions and clicks (90 days, PageTraffic); null when the site has
     * no Search Console data. Rows without a page get no key: their gain is their priority alone.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    public static function withTraffic(Collection $rows): Collection
    {
        $traffic = app(PageTraffic::class)->forPages($rows->pluck('page_id'));

        return $rows->map(function (array $r) use ($traffic): array {
            if (($r['page_id'] ?? null) === null || ! array_key_exists((int) $r['page_id'], $traffic)) {
                return $r;
            }
            $t = $traffic[(int) $r['page_id']];

            return $r + ['impressions' => $t['impressions'] ?? null, 'clicks' => $t['clicks'] ?? null];
        });
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
     * Action types whose rows the desk approves (the candidates() queries); their applied rows are the desk's results.
     *
     * @return list<string>
     */
    public static function workActionTypes(): array
    {
        return [...SiteSuggestionTypes::APPLICABLE, ImageAlts::TYPE, ClusterOverlaps::TYPE, 'ads_negative', 'gbp_description', ProfileInfo::TYPE,
            GoogleAdsChanges::TYPE, WebHealthAudit::TYPE];
    }

    /**
     * Sonuçlar (yakup 2026-10-11): what the desk's applied work did 30 / 90 days later, measured nightly by
     * OutcomeTracker — one short line per item (its latest point, "+38 tıklama (30 gün)"), newest measurement first,
     * the verdict counts, and the record per work type that orders the packages.
     *
     * @return array{counts: array<string, int>, items: list<array{id: int, brand: string, title: string, type: string, page: string, line: string, verdict: string, label: string}>, types: list<array{type: string, label: string, won: int, neutral: int, lost: int, unclear: int, measured: int, weight: float}>, next: string|null}
     */
    public function results(?int $brandId = null, int $days = 120, int $limit = 30): array
    {
        $counts = array_fill_keys(array_keys(OutcomeTracker::VERDICT_LABELS), 0);
        $items = [];
        $rows = Suggestion::query()->with(['brand:id,name', 'page:id,url'])->where('status', Suggestion::APPLIED)->whereNotNull('measured_at')
            ->where('measured_at', '>=', now()->subDays($days))->whereIn('action_type', self::workActionTypes())
            ->when($brandId !== null, fn (Builder $q): Builder => $q->where('brand_id', $brandId))
            ->orderByDesc('measured_at')->orderByDesc('id')->limit(1000)->get(['id', 'brand_id', 'page_id', 'action_type', 'action', 'title', 'outcome', 'measured_at']);
        foreach ($rows as $row) {
            $latest = OutcomeTracker::latest($row);
            if ($latest === null) {
                continue;
            }
            $counts[$latest['verdict']]++;
            if (count($items) < $limit) {
                $items[] = ['id' => (int) $row->id, 'brand' => (string) ($row->brand?->name ?? '—'), 'title' => (string) $row->title,
                    'type' => self::workTypeLabel(OutcomeTracker::workType((string) $row->action_type, (array) $row->action)), 'page' => (string) ($row->page?->url ?? ''),
                    'line' => $latest['line'], 'verdict' => $latest['verdict'], 'label' => OutcomeTracker::VERDICT_LABELS[$latest['verdict']] ?? $latest['verdict']];
            }
        }
        $types = collect($this->learned())->filter(fn (array $t, string $type): bool => in_array(explode('.', $type)[0], self::workActionTypes(), true) && $t['measured'] > 0)
            ->map(fn (array $t, string $type): array => ['type' => $type, 'label' => self::workTypeLabel($type)] + $t)
            ->sortByDesc('weight')->values()->all();

        // Nothing measured yet: the first applied work still waiting for its 30th day says when the first result comes.
        $next = $items !== [] ? null : Suggestion::query()->where('status', Suggestion::APPLIED)->whereNull('measured_at')->whereNotNull('applied_at')
            ->whereIn('action_type', self::workActionTypes())->when($brandId !== null, fn (Builder $q): Builder => $q->where('brand_id', $brandId))->min('applied_at');

        return ['counts' => $counts, 'items' => $items, 'types' => $types,
            'next' => $next !== null ? CarbonImmutable::parse($next)->addDays(OutcomeTracker::POINTS['d30'])->timezone('Europe/Istanbul')->format('d.m.Y') : null];
    }

    /** "ads_change.budget" → "Google Ads ayarı · budget", "title_description" → "başlık / açıklama". */
    public static function workTypeLabel(string $workType): string
    {
        [$type, $field] = array_pad(explode('.', $workType, 2), 2, null);
        $label = match ($type) {
            'ads_negative' => self::KINDS[self::ADS_NEGATIVE],
            GoogleAdsChanges::TYPE => self::KINDS[self::ADS_CHANGE],
            'gbp_description' => self::KINDS[self::GBP_DESCRIPTION],
            ProfileInfo::TYPE => self::KINDS[self::GBP_FIELDS],
            WebHealthAudit::TYPE => self::KINDS[self::WEB_FIX],
            ClusterOverlaps::TYPE => self::KINDS[self::REDIRECT],
            default => Str::ucfirst(SiteSuggestionTypes::label((string) $type)),
        };

        return $field === null ? $label : $label.' · '.(ProfileInfo::FIELD_LABELS[$field] ?? $field);
    }

    /**
     * Marka sağlığı: per brand the work left before it is perfect and what was done in the last two weeks.
     * "Kusursuz" percent = fixes applied in the last TRACK_DAYS days / (applied + still open rows). Open work is counted
     * in packages (`open`, self::packages: same brand + kind + lane), the rows behind them in `rows`. Brands are sorted
     * by the expected gain of their open work (self::gain times the learned weight of the row's work type, 0.8 … 1.2,
     * see packages()), then by critical count.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array{brand_id: int, brand: string, open: int, rows: int, done: int, percent: int, gain: float, critical: int}>
     */
    public function health(Collection $rows): Collection
    {
        $learned = $this->learned();
        $done = Suggestion::query()->where('status', Suggestion::APPLIED)->where('applied_at', '>=', now()->subDays(self::TRACK_DAYS))
            ->whereIn('brand_id', $rows->pluck('brand_id')->unique())->selectRaw('brand_id, count(*) as aggregate')->groupBy('brand_id')->pluck('aggregate', 'brand_id');

        return $rows->groupBy('brand_id')->map(function (Collection $group, int $brandId) use ($done, $learned): array {
            $finished = (int) ($done[$brandId] ?? 0);

            return ['brand_id' => $brandId, 'brand' => (string) $group->first()['brand'], 'open' => $group->groupBy(fn (array $r): string => self::packageKey($r))->count(),
                'rows' => $group->count(), 'done' => $finished, 'percent' => (int) round(100 * $finished / max(1, $finished + $group->count())),
                'gain' => round((float) $group->sum(fn (array $r): float => self::gain($r) * (float) ($learned[$r['work_type'] ?? '']['weight'] ?? 1.0)), 2),
                'critical' => $group->filter(fn (array $r): bool => ($r['priority'] ?? 3) <= 1)->count()];
        })->sortBy([['gain', 'desc'], ['critical', 'desc'], ['open', 'desc'], ['brand', 'asc']])->values();
    }

    /**
     * Expected gain of one row: its priority weight (1 → 3, 2 → 2, else 1) times its page's Search Console reach
     * (1 + log10(1 + impressions + 10 × clicks), 90 days). A page Google did not show gains 0; a row without a page or
     * on a site without Search Console data keeps its priority weight.
     *
     * @param  array<string, mixed>  $row
     */
    public static function gain(array $row): float
    {
        $weight = (float) max(1, 4 - (int) ($row['priority'] ?? 3));
        if (($row['impressions'] ?? null) === null) {
            return $weight;
        }
        $impressions = (int) $row['impressions'];

        return $impressions <= 0 ? 0.0 : round($weight * (1 + log10(1 + $impressions + 10 * (int) ($row['clicks'] ?? 0))), 2);
    }

    public const array WRITE_LABELS = [
        ExternalWriteAction::ACTION_SITE_FIX => 'Site düzeltmesi', ExternalWriteAction::ACTION_ADS_CHANGE => 'Google Ads ayarı',
        ExternalWriteAction::ACTION_NEGATIVE_LIST_ADD => 'Google Ads negatif kelime', ExternalWriteAction::ACTION_PROFILE_FIELDS => 'İşletme Profili bilgisi',
        ExternalWriteAction::ACTION_PROFILE_UPDATE => 'İşletme Profili açıklaması', ExternalWriteAction::ACTION_CONTENT_DRAFT => 'Sayfa metni taslağı',
        ExternalWriteAction::ACTION_CONTENT_APPLY => 'Sayfa metni canlıya', ExternalWriteAction::ACTION_CONNECTOR_UPDATE => 'Eklenti güncellemesi',
        ExternalWriteAction::ACTION_MEDIA_UPLOAD => 'İşletme Profili fotoğrafı', ExternalWriteAction::ACTION_LOCAL_POST => 'İşletme Profili gönderisi',
        ExternalWriteAction::ACTION_REVIEW_REPLY => 'Yorum yanıtı', ExternalWriteAction::ACTION_ARTICLE_DRAFTS => 'Yazı taslağı',
        ExternalWriteAction::ACTION_DRAFT_CREATE => 'Yazı taslağı', ExternalWriteAction::ACTION_POST_DATE => 'Yazı tarihi', ExternalWriteAction::ACTION_SITE_BUILD => 'Site kurulumu', ExternalWriteAction::ACTION_UPDATE_APPLY => 'WordPress güncellemesi',
    ];

    public const array CHANGE_LABELS = [
        'seo_title' => 'başlık', 'seo_description' => 'açıklama', 'canonical' => 'asıl adres (canonical)', 'noindex' => 'dizin ayarı',
        'alt_text' => 'görsel alt metni', 'schema' => 'yapılandırılmış veri', 'redirect' => 'yönlendirme', 'merge_redirect' => 'sayfayı birleştirme (yönlendirme)',
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
     * what changed (counts per change type), what could not be done and why (and how many of those are back on the desk), and the single writes for undo.
     *
     * @return Collection<int, array{key: string, at: string, asset: string, label: string, sentence: string, problems: list<string>, ok: int, partial: int, failed: int, pending: int, returned: int, items: list<array{id: int, title: string, status: string, error: string, undoable: bool}>}>
     */
    public function done(?int $brandId = null, int $days = 7): Collection
    {
        $writes = ExternalWriteAction::query()->with('digitalAsset:id,name')->whereNotNull('suggestion_id')
            ->when($brandId !== null, fn (Builder $q): Builder => $q->where('brand_id', $brandId))
            ->where('created_at', '>=', now()->subDays($days))->latest('id')->limit(1000)->get();
        $suggestions = Suggestion::query()->with('page:id,url')->whereIn('id', $writes->pluck('suggestion_id')->unique())->get(['id', 'title', 'page_id', 'status'])->keyBy('id');

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
                // A failed write whose suggestion is open again went back to the desk (RepairVerifier) and leaves with the next send.
                $returned = $group->filter(fn (ExternalWriteAction $w): bool => in_array($w->status, ['failed', 'rejected', 'cancelled'], true)
                    && ($suggestions[$w->suggestion_id] ?? null)?->status === Suggestion::OPEN)->pluck('suggestion_id')->unique()->count();
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
                    'ok' => (int) ($statuses['ok'] ?? 0), 'partial' => (int) ($statuses['partial'] ?? 0), 'failed' => (int) ($statuses['failed'] ?? 0), 'pending' => (int) ($statuses['pending'] ?? 0), 'returned' => $returned,
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
     * @return array{applied: int, skipped_high: int, failed: list<string>, failed_ids: list<int>}
     */
    public function approve(array $ids, User $user): array
    {
        $rows = $this->rows(withQueued: true)->whereIn('id', array_map('intval', $ids))->values();
        $result = ['applied' => 0, 'skipped_high' => 0, 'failed' => [], 'failed_ids' => []];
        if ($rows->count() > 1) {
            $result['skipped_high'] = $rows->where('risk', self::HIGH)->count();
            $rows = $rows->where('risk', '!=', self::HIGH)->values();
        }
        $suggestions = Suggestion::query()->whereIn('id', $rows->pluck('id'))->get()->keyBy('id');
        /** @param  Collection<int, array<string, mixed>>  $group  every row the failed send carried */
        $fail = function (Collection $group, Throwable $error) use (&$result): void {
            $row = $group->first();
            $message = (string) ($error instanceof ValidationException ? collect($error->errors())->flatten()->first() : $error->getMessage());
            $result['failed'][] = $row['brand'].' · '.$row['title'].': '.$message;
            $ids = $group->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $result['failed_ids'] = [...$result['failed_ids'], ...$ids];
            self::markError($ids, $message);
            if (! $error instanceof ValidationException) {
                report($error);
            }
        };

        foreach ($rows->groupBy('kind') as $kind => $group) {
            if ($kind === self::REDIRECT) {
                try {
                    $result['applied'] += app(ClusterOverlaps::class)->redirectMany($group->map(fn (array $r): Suggestion => $suggestions[$r['id']])->values(), $user);
                } catch (Throwable $error) {
                    $fail($group, $error);
                }

                continue;
            }
            // Google Ads writes off: "Yaptım" hides the row for a week; the nightly Ads check closes it when the account no
            // longer shows the problem, or brings it back.
            if (in_array($kind, [self::ADS_NEGATIVE, self::ADS_CHANGE], true) && ! ExternalWriteService::adsWritesOn()) {
                foreach ($group as $row) {
                    GoogleAdsSuggestions::doneByHand($suggestions[$row['id']], $user);
                    $result['applied']++;
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
                        $fail($negatives, $error);
                    }
                }

                continue;
            }
            foreach ($group as $row) {
                $suggestion = $suggestions[$row['id']];
                try {
                    match ($kind) {
                        self::SITE_FIELDS, self::SITE_CONTENT => $suggestion->action_type === BrokenTranslations::TYPE
                            ? app(BrokenTranslations::class)->approve($suggestion, $user) : app(ChangeApplier::class)->approve($suggestion, $user),
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
                    $fail(collect([$row]), $error);
                }
            }
        }

        $result['failed_ids'] = array_values(array_unique($result['failed_ids']));
        self::forgetSummary();

        return $result;
    }

    /**
     * Onayla (yakup 2026-10-13: an approval of many rows ended in nginx 504): the click only checks which rows may be
     * approved (high risk one by one), marks them queued — they leave the list at once — and hands the approval to
     * ApproveRepairRowsJob on the writes queue. What was written shows under "Yapılanlar"; a row that could not be sent
     * returns with its reason.
     *
     * @param  list<int>  $ids
     * @return array{applied: int, skipped_high: int, failed: list<string>}
     */
    public function queueApproval(array $ids, User $user): array
    {
        $rows = $this->rows()->whereIn('id', array_map('intval', $ids))->values();
        $result = ['applied' => 0, 'skipped_high' => 0, 'failed' => []];
        if ($rows->count() > 1) {
            $result['skipped_high'] = $rows->where('risk', self::HIGH)->count();
            $rows = $rows->where('risk', '!=', self::HIGH)->values();
        }
        $queue = $rows->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
        if ($queue === []) {
            return $result;
        }
        foreach (Suggestion::query()->whereIn('id', $queue)->get() as $suggestion) {
            $suggestion->forceFill(['action' => array_merge((array) $suggestion->action, [self::QUEUED_AT => now()->toIso8601String(), 'desk_queued_by' => $user->id])])->save();
        }
        foreach (array_chunk($queue, 200) as $chunk) {
            dispatch(new ApproveRepairRowsJob($chunk, (int) $user->id))->onQueue((string) config('queue.writes_queue', 'default'));
        }
        $result['applied'] = count($queue);
        self::forgetSummary();

        return $result;
    }

    /** Rows approved and still waiting for ApproveRepairRowsJob (the desk shows "sırada" and refreshes meanwhile). */
    public function queuedCount(?int $brandId = null): int
    {
        return Suggestion::query()->whereNotNull('action->'.self::QUEUED_AT)
            ->where('updated_at', '>=', now()->subMinutes(self::QUEUED_MINUTES))
            ->when($brandId !== null, fn (Builder $q): Builder => $q->where('brand_id', $brandId))->count();
    }

    /**
     * The approval job finished (or died): the queued mark goes; $error (whole batch) or the per-row errors stay on the
     * rows that were not sent, the others lose an earlier error.
     *
     * @param  list<int>  $ids
     * @param  list<int>  $failedIds
     */
    public function releaseQueued(array $ids, ?string $error = null, array $failedIds = []): void
    {
        foreach (Suggestion::query()->whereIn('id', $ids)->get() as $suggestion) {
            $action = (array) $suggestion->action;
            unset($action[self::QUEUED_AT], $action['desk_queued_by']);
            if ($error !== null) {
                $action[self::DESK_ERROR] = mb_substr($error, 0, 300);
            } elseif (! in_array((int) $suggestion->id, $failedIds, true)) {
                unset($action[self::DESK_ERROR]);
            }
            $suggestion->forceFill(['action' => $action])->save();
        }
        self::forgetSummary();
    }

    public static function isQueued(Suggestion $suggestion): bool
    {
        $at = data_get($suggestion->action, self::QUEUED_AT);
        if (! is_string($at) || $at === '') {
            return false;
        }
        try {
            return CarbonImmutable::parse($at)->gt(now()->subMinutes(self::QUEUED_MINUTES));
        } catch (Throwable) {
            return false;
        }
    }

    /** @param  list<int>  $ids */
    private static function markError(array $ids, string $message): void
    {
        foreach (Suggestion::query()->whereIn('id', $ids)->get() as $suggestion) {
            $suggestion->forceFill(['action' => array_merge((array) $suggestion->action, [self::DESK_ERROR => mb_substr($message, 0, 300)])])->save();
        }
    }

    /**
     * Reddet: the rows leave the desk and their generators do not bring them back unless the evidence changes.
     *
     * @param  list<int>  $ids
     */
    public function reject(array $ids, User $user, ?string $reason = null): int
    {
        $ids = $this->rows()->whereIn('id', array_map('intval', $ids))->pluck('id')->all();
        self::forgetSummary();

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
            $base()->with('page:id,url,website_asset_id,title,h1,wp_post_id')->where('channel', 'search')->whereIn('action_type', SiteSuggestionTypes::APPLICABLE)->where('status', '!=', Suggestion::APPROVED)->whereNotNull('action->proposal'),
            $base()->where('action_type', ImageAlts::TYPE),
            $base()->with('page:id,url,website_asset_id,title,h1,wp_post_id')->where('action_type', BrokenTranslations::TYPE)->where('status', '!=', Suggestion::APPROVED)->whereNotNull('action->proposal'),
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
        $lastError = $action[self::DESK_ERROR] ?? $action['last_write_error'] ?? $action['merge_error'] ?? null;
        $base = ['id' => (int) $s->id, 'brand_id' => (int) $s->brand_id, 'brand' => $brand, 'channel' => (string) $s->channel,
            'work_type' => OutcomeTracker::workType((string) $s->action_type, $action),
            'title' => (string) $s->title, 'priority' => (int) ($s->priority ?? 3), 'page_id' => $s->page_id !== null ? (int) $s->page_id : null,
            'page_title' => $s->relationLoaded('page') ? (string) ($s->page?->h1 ?: $s->page?->title ?? '') : '',
            'reason' => trim((string) $s->reason.(filled($lastError) ? ' · Önceki gönderim başarısız: '.$lastError : ''), ' ·')];
        if ($s->status === Suggestion::SNOOZED && $s->snoozed_until !== null && $s->snoozed_until->isFuture()) {
            return null;
        }

        $row = match ((string) $s->action_type) {
            ImageAlts::TYPE => $this->altRow($base, $action),
            ClusterOverlaps::TYPE => ClusterOverlaps::mergeable($s) ? $base + ['kind' => self::REDIRECT, 'risk' => self::MEDIUM,
                'target' => (string) ($action['overlap_url'] ?? ''), 'before' => [(string) ($action['overlap_url'] ?? '')],
                'after' => ['Ziyaretçiler şu sayfaya gider: '.($action['main_url'] ?? ''), 'Eski sayfa silinmez, taslağa alınır'], 'asset_id' => isset($action['site_id']) ? (int) $action['site_id'] : null] : null,
            'ads_negative' => ($action['scope'] ?? '') === 'shared' && ! isset($action['sending_write_id']) && filled($action['text'] ?? null)
                ? $base + ['kind' => self::ADS_NEGATIVE, 'risk' => ! ExternalWriteService::adsWritesOn() ? self::MANUAL : (($action['blocks'] ?? []) === [] ? self::LOW : self::HIGH), 'target' => 'Paylaşılan negatif listesi', 'before' => [],
                    'after' => [GoogleAdsSuggestions::line((string) $action['text'], (string) ($action['match_type'] ?? 'PHRASE')).(isset($action['cost']) ? ' · boşa giden '.$action['cost'] : '')
                        .(($action['blocks'] ?? []) !== [] ? ' · engelleyebileceği: '.implode(', ', array_slice((array) $action['blocks'], 0, 5)) : '')]] : null,
            'gbp_description' => filled($action['proposed'] ?? null) ? $base + ['kind' => self::GBP_DESCRIPTION, 'risk' => self::LOW, 'target' => 'İşletme açıklaması',
                'before' => [(string) ($action['current'] ?? '—')], 'after' => [(string) $action['proposed']], 'editable' => ['description']] : null,
            ProfileInfo::TYPE => filled($action['fields'] ?? null) ? $base + ['kind' => self::GBP_FIELDS, 'risk' => ($action['field'] ?? null) === 'regular_hours' ? self::HIGH : self::MEDIUM,
                'target' => trim(($action['location'] ?? '').' · '.(ProfileInfo::FIELD_LABELS[$action['field'] ?? ''] ?? 'Profil bilgisi'), ' ·'), 'before' => [(string) ($action['current'] ?? '') ?: '—'],
                'after' => [(string) ($action['proposed'] ?? '')]] : null,
            // Budget, pause and location changes spend money: never in a bulk approval (one by one); while Google Ads writes
            // are off (yakup 2026-10-21) every Ads row is the operator's to do in Google Ads ("Yaptım").
            GoogleAdsChanges::TYPE => filled($action['field'] ?? null) ? $base + ['kind' => self::ADS_CHANGE,
                'risk' => ! ExternalWriteService::adsWritesOn() ? self::MANUAL : (in_array($action['field'] ?? null, self::ADS_SINGLE_FIELDS, true) ? self::HIGH
                    : (in_array($action['risk'] ?? null, [self::LOW, self::MEDIUM, self::HIGH], true) ? $action['risk'] : self::MEDIUM)),
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

        if ($row === null) {
            return null;
        }
        $row += ['editable' => [], 'asset_id' => $s->target_id !== null ? (int) $s->target_id : null];

        return $row + ['open' => $this->openLink($s, $row, $action)];
    }

    /**
     * The row's "Aç ↗" (App\Services\Operator\ExternalLinks): the post's editor in WordPress (signed in), the campaign in
     * Google Ads, the profile on Maps; else the page itself or the asset's own account.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>|null
     */
    private function openLink(Suggestion $s, array $row, array $action): ?array
    {
        $links = app(ExternalLinks::class);
        if (preg_match('~customers/(\d+)/campaigns/(\d+)~', (string) ($action['resource'] ?? ''), $m) === 1) {
            return $links->googleAdsCampaign($m[1], $m[2], 'Google Ads');
        }
        $asset = $row['asset_id'] !== null ? ($this->linkAssets[$row['asset_id']] ??= DigitalAsset::query()->find($row['asset_id'])) : null;
        if ($asset === null) {
            return null;
        }
        $page = $s->relationLoaded('page') ? $s->page : null;
        if ($asset->type === 'website' && $page?->wp_post_id !== null && $links->isWordPress($asset)) {
            return $links->wordPressPost($asset, (int) $page->wp_post_id);
        }
        $own = $this->linkSets[$asset->id] ??= $links->forAsset($asset);
        $target = (string) $row['target'];
        if ($asset->type === 'website' && str_starts_with($target, 'http')) {
            return ['key' => 'page:'.$target, 'label' => 'Sayfayı aç', 'hint' => 'Sayfayı sitede aç', 'url' => $target, 'login' => null];
        }
        $prefer = ['website' => 'wordpress', 'google_ads' => 'google_ads', 'meta_ads' => 'meta_ads', 'google_business_profile' => 'gbp:'][$asset->type] ?? '';
        $link = collect($own)->first(fn (array $l): bool => $prefer !== '' && str_starts_with($l['key'], $prefer)) ?? ($own[0] ?? null);

        return $link === null ? null : array_merge($link, ['label' => match (true) {
            str_starts_with($link['key'], 'wordpress') => 'WordPress',
            str_starts_with($link['key'], 'google_ads') => 'Google Ads',
            str_starts_with($link['key'], 'gbp:manager') => 'Profiller',
            str_starts_with($link['key'], 'gbp') => 'Haritada aç',
            default => 'Aç',
        }]);
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
        $translation = $s->action_type === BrokenTranslations::TYPE;
        if ($html) {
            $after[] = $translation ? 'Sayfa metni kaynak sürümünden düzgün çeviriyle değişir (onayla canlıya gider, geri alınabilir)' : 'Sayfa metni güncellenir (WordPress taslağı, sonra "Canlıya al")';
        }
        if ($after === []) {
            return null;
        }

        return $base + ['kind' => $html ? self::SITE_CONTENT : self::SITE_FIELDS,
            // A broken translation's fix is a faithful translation of the page's own source version, checked by rules.
            'risk' => $html ? ($translation ? self::MEDIUM : self::HIGH) : (filled($new['schema_json'] ?? null) ? self::MEDIUM : self::LOW),
            'target' => (string) ($s->page?->url ?? ''), 'before' => $before, 'after' => $after, 'editable' => $editable,
            'asset_id' => $s->page?->website_asset_id !== null ? (int) $s->page->website_asset_id : null];
    }
}
