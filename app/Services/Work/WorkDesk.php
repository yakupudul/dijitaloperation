<?php

namespace App\Services\Work;

use App\Models\AssetAlert;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Analyst\AnalystDecisionStore;
use App\Services\Brand\BrandAudit;
use App\Services\Brand\BrandGaps;
use App\Services\DataStatus\DataStatusReader;
use App\Services\GoogleAds\GoogleAdsSuggestions;
use App\Services\Operator\OperatorPortfolioPresenter;
use App\Services\SeoTasks\SeoText;
use App\Services\Site\Clarity\ClarityRules;
use App\Services\Site\ClusterOverlaps;
use App\Services\Site\ClusterPageShares;
use App\Services\Site\ContentPlanner;
use App\Services\Site\ImageAlts;
use App\Services\Site\SiteSuggestions;
use App\Services\Site\SiteSuggestionTypes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Genel işler (yakup, 2026-10-03): every brand's open work in one place, split into seven tabs. It reads what MoxDOP
 * already produces — the ONE `suggestions` table (search · maps · google_ads · meta) and the open asset alerts — and
 * writes nothing new to any site or account: Onayla / Yaptım / Reddet / Ertele use the same services as the asset
 * screens; writes to a site or Business Profile still happen on the asset screen after approval.
 *
 * Row shape: kind (suggestion | alert), id, rank (0 = most urgent), brand, asset, url, title, reason, who, stage,
 * verification, actions.
 */
final class WorkDesk
{
    public const array TABS = [
        'kurulum' => 'Marka kurulumu',
        'icerik' => 'Web site SEO içerikler',
        'teknik' => 'Teknik SEO',
        'saglik' => 'Teknik sağlık',
        'ads' => 'Google Ads',
        'meta' => 'Meta Ads',
        'isletme' => 'Google İşletme',
    ];

    public const string VIEW_OPEN = 'acik';

    public const string VIEW_DONE = 'yapildi';

    /** Website suggestion types that change a page's fields or markup (the rest of channel `search` is content). */
    public const array TECHNICAL_TYPES = ['title_description', 'internal_links', 'technical_seo', 'conversion', ImageAlts::TYPE];

    /** Brand setup work (BrandGaps / BrandAudit): fixed inside MoxDOP and closed by the system when the gap is gone. */
    public const array SETUP_TYPES = ['brand_gap', 'brand_audit'];

    /** Row buttons beyond Onayla / Yaptım: code => label. */
    public const array ACTIONS = [
        'gap_fix' => 'Onayla ve yap',
        'audit_fix' => 'Düzelt',
        'audit_accept' => 'Doğru, bırak',
        'merge' => '301 ile birleştir',
        'keep' => 'Ayrı kalsın',
        'send_draft' => 'WordPress\'e taslak gönder',
    ];

    public const array SEVERITY_RANK = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3, 'info' => 4];

    /** How many rows a tab lists (most urgent first). */
    public const int LIMIT = 2000;

    /** Rows (overlaps: clusters) a work card shows before "Tümünü göster". */
    public const int PER_GROUP = 5;

    /** Applied items stay under "Yapıldı" this long. */
    public const int DONE_DAYS = 30;

    private const array TAB_CHANNEL = ['kurulum' => 'search', 'icerik' => 'search', 'teknik' => 'search', 'saglik' => 'search', 'ads' => 'google_ads', 'meta' => 'meta', 'isletme' => 'maps'];

    private const array TAB_ALERT_TYPES = [
        'saglik' => ['website'],
        'ads' => ['google_ads'],
        'meta' => ['meta_ads'],
        'isletme' => ['google_business_profile', 'gbp'],
    ];

    public function __construct(
        private readonly AnalystDecisionStore $decisions,
        private readonly SiteSuggestions $siteSuggestions,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(string $tab, string $view = self::VIEW_OPEN, ?int $brandId = null): Collection
    {
        $tab = isset(self::TABS[$tab]) ? $tab : 'icerik';
        $rows = collect();
        if (isset(self::TAB_CHANNEL[$tab])) {
            $suggestions = $this->suggestionQuery($tab, $view, $brandId)
                ->when($tab === 'icerik' && $view === self::VIEW_OPEN, fn (Builder $q): Builder => $q->where('action_type', '!=', SiteSuggestionTypes::CONTENT))
                ->with(['brand:id,name', 'cluster:id,name'])
                ->orderBy($view === self::VIEW_DONE ? 'applied_at' : 'priority', $view === self::VIEW_DONE ? 'desc' : 'asc')
                ->orderByDesc('id')->limit(self::LIMIT)->get();
            $assets = $this->assetsFor($suggestions);
            $rows = $suggestions->map(fn (Suggestion $s): array => $this->suggestionRow($s, $assets));
        }
        if ($view === self::VIEW_OPEN && isset(self::TAB_ALERT_TYPES[$tab])) {
            $alerts = $this->alertQuery($tab, $brandId)->with(['brand:id,name', 'digitalAsset'])->limit(self::LIMIT)->get();
            $rows = $alerts->map(fn (AssetAlert $a): array => $this->alertRow($a))->concat($rows);
        }

        return $view === self::VIEW_DONE ? $rows->values()
            : $rows->sortBy([['rank', 'asc'], ['seen', 'desc']])->take(self::LIMIT)->values();
    }

    /** @return array<string, int> open items per tab */
    public function counts(?int $brandId = null): array
    {
        $counts = [];
        foreach (array_keys(self::TABS) as $tab) {
            $count = isset(self::TAB_CHANNEL[$tab]) ? $this->suggestionQuery($tab, self::VIEW_OPEN, $brandId)->count() : 0;
            $counts[$tab] = $count + (isset(self::TAB_ALERT_TYPES[$tab]) ? $this->alertQuery($tab, $brandId)->count() : 0);
        }

        return $counts;
    }

    /** @return array<int, int> open items of one tab per brand id (the brand filter shows them) */
    public function brandCounts(string $tab): array
    {
        $tab = isset(self::TABS[$tab]) ? $tab : 'icerik';
        $counts = isset(self::TAB_CHANNEL[$tab]) ? $this->suggestionQuery($tab, self::VIEW_OPEN, null)
            ->groupBy('brand_id')->selectRaw('brand_id, count(*) as n')->pluck('n', 'brand_id')->map(fn ($n): int => (int) $n)->all() : [];
        if (isset(self::TAB_ALERT_TYPES[$tab])) {
            $alerts = $this->alertQuery($tab, null)->reorder()->groupBy('brand_id')->selectRaw('brand_id, count(*) as n')->pluck('n', 'brand_id');
            foreach ($alerts as $brandId => $n) {
                $counts[(int) $brandId] = ($counts[(int) $brandId] ?? 0) + (int) $n;
            }
        }

        return $counts;
    }

    /**
     * The list as an issue report (yakup, 2026-10-04: "aynı iş bir marka için tekrar tekrar söylüyor"): one section per
     * brand, in it one card per site · work type with its rule said once; overlaps further by cluster (the main page
     * once, the overlapping pages under it). Open work: most urgent brand and card first; done work keeps its order.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array{brand_id: ?int, brand: string, count: int, urgent: int, rank: int, groups: list<array<string, mixed>>}>
     */
    public static function groups(Collection $rows, bool $open = true): array
    {
        $sections = [];
        foreach ($rows as $row) {
            $brandKey = (int) ($row['brand_id'] ?? 0);
            $key = $brandKey.'|'.$row['kind'].'|'.$row['type'].'|'.($row['asset'] ?? '');
            $sections[$brandKey] ??= ['brand_id' => $row['brand_id'] ?? null, 'brand' => (string) ($row['brand'] ?? '—'), 'count' => 0, 'urgent' => 0, 'rank' => PHP_INT_MAX, 'groups' => []];
            $sections[$brandKey]['groups'][$key] ??= ['key' => md5($key), 'type' => (string) $row['type'], 'asset' => $row['asset'] ?? null, 'items' => []];
            $sections[$brandKey]['groups'][$key]['items'][] = $row;
        }
        foreach ($sections as &$section) {
            foreach ($section['groups'] as &$group) {
                $items = $group['items'];
                $group['count'] = count($items);
                $group['urgent'] = count(array_filter($items, fn (array $r): bool => $r['rank'] <= 1));
                $group['rank'] = min(array_column($items, 'rank'));
                $whos = array_unique(array_column($items, 'who'));
                $urls = array_unique(array_map(fn (array $r): string => (string) $r['url'], $items));
                $overlap = ($items[0]['overlap'] ?? null) !== null;
                $group['who'] = ! $overlap && count($whos) === 1 ? (string) $whos[0] : null;
                $group['about'] = $overlap ? 'Aynı arama ihtiyacına birden çok sayfa yanıt veriyor. «301 öneriliyor» olan sayfa ana sayfaya yönlendirilir (sistem siteye yazar, geri alınabilir); «Ayrıştır» olanın metni kendi ihtiyacına odaklanır, çakışma kalkınca iş kendisi kapanır.' : $group['who'];
                $group['url'] = count($urls) === 1 && $urls[0] !== '' ? $items[0]['url'] : null;
                $group['url_label'] = $group['url'] !== null ? ($items[0]['external'] ? $items[0]['url_label'] : 'Aç →') : null;
                $group['external'] = $group['url'] !== null && $items[0]['external'];
                $group['clusters'] = $overlap ? array_values(array_reduce($items, function (array $carry, array $r): array {
                    $id = (int) ($r['overlap']['cluster_id'] ?? 0).'|'.$r['overlap']['main_path'];
                    $carry[$id] ??= ['name' => $r['overlap']['cluster'], 'main_path' => $r['overlap']['main_path'], 'main_url' => $r['overlap']['main_url'], 'items' => []];
                    $carry[$id]['items'][] = $r;

                    return $carry;
                }, [])) : null;
                $section['count'] += $group['count'];
                $section['urgent'] += $group['urgent'];
                $section['rank'] = min($section['rank'], $group['rank']);
            }
            unset($group);
            $section['groups'] = array_values($section['groups']);
            if ($open) {
                usort($section['groups'], fn (array $a, array $b): int => [$a['rank'], $b['count']] <=> [$b['rank'], $a['count']]);
            }
        }
        unset($section);
        $sections = array_values($sections);
        if ($open) {
            usort($sections, fn (array $a, array $b): int => [$a['rank'], $b['urgent'], $b['count']] <=> [$b['rank'], $a['urgent'], $a['count']]);
        }

        return $sections;
    }

    /**
     * "Hepsini 7 gün ertele" on one card of the open list; an approved item keeps its approval. Returns how many items
     * were snoozed.
     */
    public function snoozeGroup(string $tab, string $key, ?int $brandId, User $user): int
    {
        $group = collect(self::groups($this->rows($tab, self::VIEW_OPEN, $brandId)))->flatMap(fn (array $section): array => $section['groups'])->firstWhere('key', $key);
        $items = array_values(array_filter($group['items'] ?? [], fn (array $row): bool => $row['status'] !== Suggestion::APPROVED));
        if ($items === []) {
            throw ValidationException::withMessages(['work' => 'Bu iş grubu artık listede yok.']);
        }
        foreach ($items as $row) {
            $this->snooze($row['kind'], (int) $row['id'], 7, $user);
        }

        return count($items);
    }

    /** @return array<string, int> urgent (critical / high or priority 1) open items per tab */
    public function urgent(?int $brandId = null): array
    {
        $urgent = [];
        foreach (array_keys(self::TABS) as $tab) {
            $count = isset(self::TAB_CHANNEL[$tab]) ? $this->suggestionQuery($tab, self::VIEW_OPEN, $brandId)->where('priority', '<=', 1)->count() : 0;
            $urgent[$tab] = $count + (isset(self::TAB_ALERT_TYPES[$tab]) ? $this->alertQuery($tab, $brandId)->whereIn('severity', ['critical', 'high'])->count() : 0);
        }

        return $urgent;
    }

    /** Onayla: a website suggestion goes to its approved queue (titles are then written, changes applied on the site screen). */
    public function approve(int $id, User $user): string
    {
        $suggestion = $this->suggestion($id);
        if (in_array($suggestion->action_type, [...self::SETUP_TYPES, ClusterOverlaps::TYPE], true)) {
            throw ValidationException::withMessages(['work' => 'Bu işin kendi düğmesi var.']);
        }
        if ($suggestion->action_type === ClarityRules::TYPE) {
            throw ValidationException::withMessages(['work' => 'Bu iş elle yapılır: yaptıktan sonra "Yaptım" deyin.']);
        }
        if ($suggestion->channel === 'search') {
            $this->siteSuggestions->approve($suggestion, $user);

            return $suggestion->action_type === SiteSuggestionTypes::CONTENT ? 'Başlık onaylandı; yazı kuyruğa girer.' : 'Onaylandı; sitede "AI ile yap" ile uygulanır.';
        }
        if ($suggestion->channel === 'google_ads') {
            app(GoogleAdsSuggestions::class)->approve($suggestion, $user);

            return 'Onaylandı.';
        }
        throw ValidationException::withMessages(['work' => 'Bu iş elle yapılır: yaptıktan sonra "Yaptım" deyin.']);
    }

    /**
     * Yaptım: the operator did it (on Google, Meta or the site). A system-check item waits for the next pull to confirm
     * it; other items are applied with the outcome baseline.
     */
    public function done(int $id, User $user, ?string $note = null): string
    {
        $suggestion = $this->suggestion($id);
        if (! in_array($suggestion->status, [Suggestion::OPEN, Suggestion::RECHECK, Suggestion::SNOOZED, Suggestion::APPROVED], true)) {
            throw ValidationException::withMessages(['work' => 'Bu iş zaten kapandı.']);
        }
        if (! $this->doneAllowed($suggestion)) {
            throw ValidationException::withMessages(['work' => 'Bu işi sistem kendisi kapatır; kendi düğmesini kullanın.']);
        }
        $this->decisions->markDone($suggestion, $user, $note);
        $checked = isset(WorkVerifier::CHECK_TYPES[(string) $suggestion->action_type]);
        $suggestion->forceFill(['verification' => $checked ? Suggestion::VERIFY_PENDING : null, 'verified_at' => null])->save();

        return $checked ? 'Yapıldı olarak işaretlendi; sistem bir sonraki veri çekiminde kontrol edecek.' : 'Yapıldı olarak işaretlendi.';
    }

    /**
     * The type's own buttons: Kurulum "Onayla ve yap" (BrandGaps), "Düzelt" / "Doğru, bırak" (BrandAudit), overlap
     * "301 ile birleştir" / "Ayrı kalsın" (ClusterOverlaps), a written article "WordPress'e taslak gönder" (ContentPlanner).
     * Each uses the same service as the brand / site screen.
     */
    public function act(int $id, string $do, User $user): string
    {
        $suggestion = $this->suggestion($id);
        if (! in_array($do, $this->actionsFor($suggestion), true)) {
            throw ValidationException::withMessages(['work' => 'Bu iş için bu adım yok.']);
        }

        return match ($do) {
            'gap_fix' => app(BrandGaps::class)->apply($suggestion, $user),
            'audit_fix' => app(BrandAudit::class)->fix($suggestion, $user),
            'audit_accept' => tap('Doğru kabul edildi; bu bulgular bir daha gelmez.', fn () => app(BrandAudit::class)->accept($suggestion, $user)),
            'merge' => tap('301 yönlendirmesi onaylandı; sitede uygulanır (geri alınabilir).', fn () => app(ClusterOverlaps::class)->redirect($suggestion, $user)),
            'keep' => tap('Ayrı kalsın; çakışma değişmedikçe geri gelmez.', fn () => app(ClusterOverlaps::class)->keep($suggestion, $user)),
            'send_draft' => tap('WordPress taslağı kuyruğa alındı (geri alınabilir).', fn () => app(ContentPlanner::class)->sendDraft($suggestion, $user)),
        };
    }

    /** @return list<string> the type's own buttons available now */
    private function actionsFor(Suggestion $s): array
    {
        $open = in_array($s->status, [Suggestion::OPEN, Suggestion::RECHECK, Suggestion::SNOOZED], true);
        $action = (array) $s->action;

        return match ((string) $s->action_type) {
            'brand_gap' => $open && ($action['fix'] ?? null) !== null ? ['gap_fix'] : [],
            'brand_audit' => $open ? ['audit_fix', 'audit_accept'] : [],
            ClusterOverlaps::TYPE => $open ? [...(($action['recommendation'] ?? null) === ClusterOverlaps::REDIRECT ? ['merge'] : []), 'keep'] : [],
            SiteSuggestionTypes::CONTENT => $s->status === Suggestion::APPROVED && is_array($action['article'] ?? null)
                && ! isset($action['article_blocked']) && ! isset($action['article_write_id']) ? ['send_draft'] : [],
            default => [],
        };
    }

    /** "Yaptım" only where the operator's own work closes it: not content (its own line), setup (system closes), a 301 merge. */
    private function doneAllowed(Suggestion $s): bool
    {
        $type = (string) $s->action_type;

        return ! ($s->channel === 'search' && $type === SiteSuggestionTypes::CONTENT)
            && ! in_array($type, self::SETUP_TYPES, true)
            && ! ($type === ClusterOverlaps::TYPE && data_get($s->action, 'recommendation') === ClusterOverlaps::REDIRECT);
    }

    /** Geri al: a "Yaptım" by mistake goes back to the open list. */
    public function reopen(int $id): void
    {
        $suggestion = $this->suggestion($id);
        if ($suggestion->status !== Suggestion::APPLIED) {
            throw ValidationException::withMessages(['work' => 'Yalnız yapıldı olarak işaretlenen iş geri alınır.']);
        }
        $suggestion->forceFill(['status' => Suggestion::OPEN, 'applied_at' => null, 'resolved_at' => null, 'resolved_by' => null,
            'verification' => null, 'verified_at' => null, 'baseline' => null, 'outcome' => null, 'measured_at' => null])->save();
    }

    public function dismiss(int $id, User $user, ?string $reason = null): void
    {
        $suggestion = $this->suggestion($id);
        if ($suggestion->channel === 'search') {
            $this->siteSuggestions->dismiss($suggestion, $user, filled($reason) ? (string) $reason : 'Genel işlerden reddedildi');

            return;
        }
        if (in_array($suggestion->status, [Suggestion::APPLIED, Suggestion::DISMISSED], true)) {
            throw ValidationException::withMessages(['work' => 'Bu iş zaten kapandı.']);
        }
        $this->decisions->dismiss($suggestion, $user, $reason);
    }

    public function snooze(string $kind, int $id, int $days, User $user): void
    {
        if ($kind === 'alert') {
            AssetAlert::query()->open()->whereKey($id)->firstOrFail()->forceFill(['snoozed_until' => now()->addDays($days), 'snoozed_by' => $user->id])->save();

            return;
        }
        $this->decisions->snooze($this->suggestion($id), $days);
    }

    /** @return Builder<Suggestion> */
    private function suggestionQuery(string $tab, string $view, ?int $brandId): Builder
    {
        $query = Suggestion::query()->where('channel', self::TAB_CHANNEL[$tab])
            ->whereHas('brand', fn (Builder $brand): Builder => $brand->operational())
            ->when($brandId !== null, fn (Builder $q): Builder => $q->where('brand_id', $brandId));
        if ($tab === 'teknik') {
            $query->whereIn('action_type', self::TECHNICAL_TYPES);
        } elseif ($tab === 'kurulum') {
            $query->whereIn('action_type', self::SETUP_TYPES);
        } elseif ($tab === 'saglik') {
            $query->where('action_type', ClarityRules::TYPE);
        } elseif ($tab === 'icerik') {
            $query->where(fn (Builder $q): Builder => $q->whereNull('action_type')
                ->orWhereNotIn('action_type', [...self::TECHNICAL_TYPES, ...self::SETUP_TYPES, ClarityRules::TYPE]));
        }
        if ($view === self::VIEW_DONE) {
            return $query->where('status', Suggestion::APPLIED)->where('applied_at', '>=', now()->subDays(self::DONE_DAYS));
        }

        // A content idea whose WordPress draft is sent stays approved; it is no longer open work.
        return $query->whereNull('action->article_write_id')->where(fn (Builder $q): Builder => $q->where('status', Suggestion::APPROVED)
            ->orWhere(fn (Builder $open): Builder => $open->actionable()));
    }

    /** @return Builder<AssetAlert> */
    private function alertQuery(string $tab, ?int $brandId): Builder
    {
        return AssetAlert::query()->active()->whereNotIn('kind', DataStatusReader::FRESHNESS_ALERT_KINDS)
            ->whereHas('digitalAsset', fn (Builder $asset): Builder => $asset->operational()->whereIn('type', self::TAB_ALERT_TYPES[$tab]))
            ->when($brandId !== null, fn (Builder $q): Builder => $q->where('brand_id', $brandId))
            ->orderByDesc('last_detected_at');
    }

    /**
     * Assets the suggestions point at: a page → its website, a site / account target → that asset.
     *
     * @param  Collection<int, Suggestion>  $suggestions
     * @return array{assets: Collection<int, DigitalAsset>, pages: array<int, int>}
     */
    private function assetsFor(Collection $suggestions): array
    {
        $pageIds = $suggestions->where('target_type', 'page')->pluck('target_id')->merge($suggestions->pluck('page_id'))->filter()->unique()->values();
        $pages = $pageIds->isEmpty() ? [] : Page::query()->whereIn('id', $pageIds)->pluck('website_asset_id', 'id')->map(fn ($id): int => (int) $id)->all();
        $assetIds = $suggestions->reject(fn (Suggestion $s): bool => in_array($s->target_type, ['page', 'brand', null], true))->pluck('target_id')
            ->merge(array_values($pages))->merge($suggestions->map(fn (Suggestion $s): int => (int) (((array) $s->action)['site_id'] ?? 0)))
            ->filter()->unique()->values();

        return ['assets' => DigitalAsset::query()->whereIn('id', $assetIds->all() ?: [0])->get()->keyBy('id'), 'pages' => $pages];
    }

    /**
     * @param  array{assets: Collection<int, DigitalAsset>, pages: array<int, int>}  $assets
     * @return array<string, mixed>
     */
    private function suggestionRow(Suggestion $s, array $assets): array
    {
        $action = (array) $s->action;
        $assetId = match (true) {
            $s->target_type === 'page' => $assets['pages'][(int) $s->target_id] ?? null,
            $s->page_id !== null && isset($assets['pages'][(int) $s->page_id]) => $assets['pages'][(int) $s->page_id],
            ! in_array($s->target_type, ['brand', null], true) => (int) $s->target_id,
            default => (int) ($action['site_id'] ?? 0) ?: null,
        };
        $asset = $assetId !== null ? $assets['assets']->get($assetId) : null;
        $type = (string) $s->action_type;
        $url = $asset !== null ? OperatorPortfolioPresenter::specialistUrl($asset) : ($s->brand_id ? route('operator.brand', ['brand' => $s->brand_id]) : null);
        if ($asset !== null && $asset->type === 'website') {
            $url = route('operator.website', ['assetId' => $asset->id, 'tab' => $s->action_type === SiteSuggestionTypes::CONTENT ? 'icerik' : 'yapilacaklar']
                + ($s->action_type === SiteSuggestionTypes::CONTENT && is_array($action['article'] ?? null) ? ['taslak' => $s->id] : []));
        }
        if ($type === 'brand_gap' && is_string($action['url'] ?? null) && $action['url'] !== '') {
            $url = (string) $action['url'];
        }
        $external = $type === ClarityRules::TYPE && is_string($action['clarity_url'] ?? null);
        if ($external) {
            $url = (string) $action['clarity_url'];
        }
        [$who, $canApprove] = $this->who($s);

        return [
            'kind' => 'suggestion', 'id' => (int) $s->id, 'rank' => max(0, (int) $s->priority), 'seen' => (string) ($s->last_seen_at ?? $s->created_at),
            'brand' => $s->brand?->name, 'brand_id' => $s->brand_id, 'asset' => $asset?->name, 'url' => $url,
            'title' => (string) $s->title, 'reason' => (string) $s->reason, 'type' => $this->typeLabel($s),
            'who' => $who, 'stage' => $this->stage($s), 'status' => (string) $s->status,
            'verification' => $s->verification, 'verified_at' => $s->verified_at,
            'applied_at' => $s->status === Suggestion::APPLIED ? $s->applied_at : null,
            'can_approve' => $canApprove && in_array($s->status, [Suggestion::OPEN, Suggestion::RECHECK, Suggestion::SNOOZED], true),
            'can_done' => in_array($s->status, [Suggestion::OPEN, Suggestion::RECHECK, Suggestion::SNOOZED, Suggestion::APPROVED], true) && $this->doneAllowed($s),
            'actions' => $this->actionsFor($s),
            'external' => $external,
            'url_label' => match (true) {
                $external => 'Clarity\'de aç ↗',
                $type === SiteSuggestionTypes::CONTENT && is_array($action['article'] ?? null) => 'Yazıyı oku →',
                $type === 'brand_gap' && ($action['fix'] ?? null) === null => 'Elle yap →',
                default => 'Aç →',
            },
            'can_reopen' => $s->status === Suggestion::APPLIED && $s->verification !== Suggestion::VERIFY_AUTO,
            'checked' => isset(WorkVerifier::CHECK_TYPES[$type]),
            'overlap' => $type === ClusterOverlaps::TYPE ? $this->overlap($s) : null,
        ];
    }

    /**
     * A cluster overlap shown under its cluster: the overlapping page, the main page and a short reason without the
     * repeated paths.
     *
     * @return array{cluster: ?string, cluster_id: ?int, main_path: string, main_url: ?string, path: string, url: ?string, recommendation: string, why: string}
     */
    private function overlap(Suggestion $s): array
    {
        $action = (array) $s->action;
        $recommendation = (string) ($action['recommendation'] ?? ClusterOverlaps::DIFFERENTIATE);
        $share = (float) ($action['share'] ?? 0);
        $mainUrl = is_string($action['main_url'] ?? null) ? $action['main_url'] : null;
        $url = is_string($action['overlap_url'] ?? null) ? $action['overlap_url'] : null;

        return [
            'cluster' => $s->cluster?->name, 'cluster_id' => $s->cluster_id !== null ? (int) $s->cluster_id : null,
            'main_path' => $mainUrl !== null ? '/'.ltrim(SeoText::urlPath($mainUrl), '/') : '', 'main_url' => $mainUrl,
            'path' => $url !== null ? '/'.ltrim(SeoText::urlPath($url), '/') : (string) $s->title, 'url' => $url,
            'recommendation' => $recommendation,
            'why' => match (true) {
                $recommendation === ClusterOverlaps::REDIRECT => 'Bu kümede az trafik alıyor. Eksik bilgisi ana sayfaya taşınıp 301 ile oraya yönlendirilsin.',
                $share >= ClusterPageShares::CONFLICT => 'Bu kümenin gösterimlerinde payı %'.(int) round($share * 100).'. Kendi ihtiyacına odaklansın, ana sayfaya bağlantı versin.',
                default => 'Başka bir kümenin hedef sayfası; birleştirilmez. Kendi ihtiyacına odaklansın, ana sayfaya bağlantı versin.',
            },
        ];
    }

    /** @return array<string, mixed> */
    private function alertRow(AssetAlert $a): array
    {
        $asset = $a->digitalAsset;

        return [
            'kind' => 'alert', 'id' => (int) $a->id, 'rank' => self::SEVERITY_RANK[(string) $a->severity] ?? 3, 'seen' => (string) $a->last_detected_at,
            'severity' => (string) $a->severity,
            'brand' => $a->brand?->name, 'brand_id' => $a->brand_id, 'asset' => $asset?->name,
            'url' => $asset !== null ? OperatorPortfolioPresenter::specialistUrl($asset) : null,
            'title' => (string) $a->title, 'reason' => (string) $a->message, 'type' => 'Uyarı',
            'who' => $a->kind === 'bad_review_unanswered' ? 'Yanıt taslağı · onayla gönder' : 'Elle · durum düzelince kendisi kapanır',
            'stage' => null, 'status' => 'open', 'verification' => null, 'verified_at' => null, 'applied_at' => null,
            'can_approve' => false, 'can_done' => false, 'can_reopen' => false, 'checked' => true, 'actions' => [], 'url_label' => 'Aç →', 'external' => false,
        ];
    }

    /** @return array{0: string, 1: bool} who does it, and whether "Onayla" applies here */
    private function who(Suggestion $s): array
    {
        $type = (string) $s->action_type;
        $action = (array) $s->action;

        return match ($s->channel) {
            'search' => match (true) {
                $type === 'brand_gap' => [($action['fix'] ?? null) !== null ? 'Onayla ve yap · sistem MoxDOP içinde düzeltir · eksik kalkınca kendisi kapanır'
                    : 'Bağlantıdan elle · eksik kalkınca kendisi kapanır', false],
                $type === ClarityRules::TYPE => ['Clarity kayıtlarına bak · sitede elle düzelt · Yaptım de, sistem sonraki çekimde kontrol eder', false],
                $type === 'brand_audit' => ['Düzelt: sistem yanlış kararı geri alır · Doğru, bırak: bir daha sorulmaz', false],
                $type === ClusterOverlaps::TYPE => [($action['recommendation'] ?? null) === ClusterOverlaps::REDIRECT
                    ? '301 ile birleştir · sistem siteye yazar (geri alınabilir)' : 'Sayfa metni elle ayrıştırılır · çakışma kalkınca kendisi kapanır', false],
                $type === SiteSuggestionTypes::CONTENT => ['Başlık onayı · Claude yazar · taslak siteye', true],
                $type === ImageAlts::TYPE || SiteSuggestionTypes::applicable($type) => ['Onayla · sistem siteye yazar', true],
                default => ['Onayla · sitede elle', true],
            },
            'google_ads' => match (true) {
                GoogleAdsSuggestions::isSharedNegative($s) => ['Onayla · sistem ortak negatif listeye ekler', true],
                in_array($type, GoogleAdsSuggestions::EDITOR_TYPES, true) => ['Onayla · Editor dosyası · elle', true],
                default => ['Elle · Yaptım de', false],
            },
            default => ['Elle · Yaptım de', false],
        };
    }

    private function typeLabel(Suggestion $s): string
    {
        $type = (string) $s->action_type;

        return match (true) {
            $type === 'brand_gap' => 'kurulum eksiği',
            $type === 'brand_audit' => 'şef denetimi',
            $type === ClarityRules::TYPE => 'ziyaretçi davranışı',
            $s->channel === 'search' => match ($type) {
                ClusterOverlaps::TYPE, ImageAlts::TYPE, SiteSuggestionTypes::CONTENT, SiteSuggestionTypes::COMPETITOR => SiteSuggestionTypes::label($type),
                default => SiteSuggestionTypes::ANALYSIS[$type] ?? 'öneri',
            },
            isset(WorkVerifier::CHECK_TYPES[$type]) => 'sistem kontrolü',
            default => 'öneri',
        };
    }

    /** Content line stage: title approval → writing → read → WordPress draft. */
    private function stage(Suggestion $s): ?string
    {
        if ($s->action_type !== SiteSuggestionTypes::CONTENT) {
            return $s->status === Suggestion::APPROVED ? 'Onaylandı · uygulanacak' : null;
        }
        $action = (array) $s->action;

        return match (true) {
            $s->status === Suggestion::APPLIED => 'Siteye gönderildi',
            isset($action['article_write_id']) => 'WordPress taslağı gönderildi',
            isset($action['article_blocked']) => 'Yazı sektör kuralına takıldı',
            isset($action['article']) => 'Yazı hazır · okunacak',
            $s->status === Suggestion::APPROVED => 'Başlık onaylı · yazılıyor',
            default => 'Başlık onayı bekliyor',
        };
    }

    private function suggestion(int $id): Suggestion
    {
        return Suggestion::query()->whereKey($id)->whereHas('brand', fn (Builder $brand): Builder => $brand->operational())->firstOrFail();
    }
}
