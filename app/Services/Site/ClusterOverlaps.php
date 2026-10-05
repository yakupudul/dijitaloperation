<?php

namespace App\Services\Site;

use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\Page;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Brand\BrandAudit;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\ExternalWrites\WordPressDraftWriter;
use App\Services\Queries\ClusterEditor;
use App\Services\SeoTasks\SeoText;
use App\Services\Site\Analysis\SitePagesReader;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Bir küme sitede birden fazla sayfayla eşleşirse: the main page of the cluster row plus the other pages that answer
 * the same need — the AI match's `also_page_ids` (content) and the pages Google shows with ≥ CONFLICT of the cluster's
 * Search Console impressions. Every overlapping page becomes one suggestion in the work list (`site.cluster_overlap`)
 * with a rule recommendation:
 * - redirect ("301 ile birleştir"): the page gets little of the cluster's traffic, is no other cluster's target and is
 *   not a service page while the main page is a blog / Q&A page — its content goes into the main page, the URL
 *   redirects there through the site's SEO plugin and the page becomes a draft (connector 1.9.0, undoable);
 * - differentiate ("Ayrıştır"): the page gets real traffic, is the target of another cluster or is a location page —
 *   it is kept and focused on its own need, with a link to the main page;
 * - review ("Ana sayfayı gözden geçir"): the page gets most of the cluster's impressions, or it is a service page and
 *   the main page is not — the main page choice is the mistake, never a 301 ("Ana sayfa bu olsun").
 * A page is proposed for a 301 in one cluster only (to one main page). The site's home page, language home pages,
 * contact / about / legal pages, pages in another language (field or /en/ path prefix) and pages tied only to other
 * services are never an overlap. Overlaps that are gone close their suggestion. Operator-added pages
 * (extra_page_ids) are never an overlap. The same cluster · main page · page pair is one suggestion whichever
 * language row found it.
 */
final class ClusterOverlaps
{
    public const string DECISION = 'site.cluster_overlap';

    public const string TYPE = 'cluster_overlap';

    public const string REDIRECT = 'redirect';

    public const string DIFFERENTIATE = 'differentiate';

    public const string REVIEW = 'review';

    /** Note of an overlap the system closed because it was gone; it reopens when the overlap comes back. */
    public const string GONE = 'Çakışma kalktı.';

    /** Page categories that sell a service: never redirected into a blog / Q&A page. */
    private const array MONEY = ['hizmet', 'lokasyon'];

    public function __construct(
        private readonly ExternalWriteService $writes,
        private readonly BrandMemoryService $memory,
        private readonly ClusterPageShares $shares,
    ) {}

    /**
     * @param  array<int, list<array{url: string, url_key: string, impressions: int, share: float}>>  $shares  cluster id → Search Console pages
     * @return int open overlaps (decided ones not counted)
     */
    public function sync(DigitalAsset $site, Brand $brand, array $shares = []): int
    {
        // Language-less rows first: a pair several rows find keeps the language row (the website tab lists it there).
        $rows = BrandClusterPage::query()->with('cluster')->where('brand_id', $brand->id)->where('website_asset_id', $site->id)
            ->where('excluded', false)->whereNotNull('page_id')->orderBy('id')->get()->filter(fn (BrandClusterPage $row): bool => $row->cluster !== null)
            ->sortBy(fn (BrandClusterPage $row): array => [filled($row->language) ? 1 : 0, (int) $row->id])->values();
        $rowIds = $rows->groupBy('cluster_id')->map(fn ($group): array => $group->pluck('id')->map(fn ($id): int => (int) $id)->all())->all();
        $pages = Page::query()->where('website_asset_id', $site->id)->get(['id', 'url', 'path', 'language', 'category'])->keyBy('id');
        $byKey = $pages->mapWithKeys(fn (Page $p): array => [SeoText::urlKey((string) $p->url) => (int) $p->id])->all();
        $targets = BrandClusterPage::query()->where('website_asset_id', $site->id)->where('excluded', false)->whereNotNull('page_id')
            ->get(['cluster_id', 'page_id'])->groupBy('page_id')->map(fn ($group): array => $group->pluck('cluster_id')->map(fn ($id): int => (int) $id)->all())->all();
        $pageServices = BrandAudit::pageServices($site);
        $found = [];
        foreach ($rows as $row) {
            $main = $pages->get((int) $row->page_id);
            if ($main === null) {
                continue;
            }
            $gsc = [];
            foreach ($shares[(int) $row->cluster_id] ?? [] as $share) {
                if (isset($byKey[$share['url_key']])) {
                    $gsc[$byKey[$share['url_key']]] = (float) $share['share'];
                }
            }
            $overlaps = (array) $row->overlap_page_ids;
            foreach ($gsc as $pageId => $share) {
                if ($share >= ClusterPageShares::CONFLICT) {
                    $overlaps[] = $pageId;
                }
            }
            $skip = [(int) $row->page_id, ...array_map('intval', (array) $row->extra_page_ids)];
            foreach (array_unique(array_map('intval', $overlaps)) as $pageId) {
                $page = $pages->get($pageId);
                if ($page === null || in_array($pageId, $skip, true) || ! self::sameLanguage((string) ($row->language ?: $main->language), $main, $page)
                    || self::never($page) || self::otherService($pageServices[$pageId] ?? [], (int) $row->cluster->service_id)) {
                    continue;
                }
                $share = $gsc[$pageId] ?? 0.0;
                $otherTarget = array_diff($targets[$pageId] ?? [], [(int) $row->cluster_id]) !== [];
                [$recommendation, $basis] = self::recommend($main, $page, $share, $gsc[(int) $main->id] ?? 0.0, $otherTarget);
                $found[] = ['row' => $row, 'main' => $main, 'page' => $page, 'share' => $share, 'recommendation' => $recommendation, 'basis' => $basis];
            }
        }
        $kept = [];
        foreach (self::oneRedirectPerPage($found) as $item) {
            $suggestion = $this->upsert($site, $brand, $item, $rowIds[(int) $item['row']->cluster_id] ?? [(int) $item['row']->id]);
            $kept[(int) $suggestion->id] = in_array($suggestion->status, [Suggestion::OPEN, Suggestion::RECHECK], true);
        }
        Suggestion::query()->where('brand_id', $brand->id)->where('decision_key', self::DECISION)
            ->whereIn('status', [Suggestion::OPEN, Suggestion::RECHECK, Suggestion::SNOOZED])->whereNotIn('id', array_keys($kept) ?: [0])->get()
            ->filter(fn (Suggestion $s): bool => (int) data_get($s->action, 'site_id') === (int) $site->id)
            ->each(fn (Suggestion $s) => $s->forceFill(['status' => Suggestion::APPLIED, 'resolved_at' => now(), 'operator_note' => self::GONE])->save());

        return count(array_filter($kept));
    }

    /** sync() with the Search Console shares of every cluster of the site (after an operator change). */
    public function refresh(DigitalAsset $site, Brand $brand): int
    {
        $clusterIds = BrandClusterPage::query()->where('brand_id', $brand->id)->where('website_asset_id', $site->id)
            ->distinct()->pluck('cluster_id')->map(fn ($id): int => (int) $id)->all();

        return $this->sync($site, $brand, $this->shares->forClusters($brand, $site, $clusterIds));
    }

    /** "301 ile birleştir" on one overlap. */
    public function redirect(Suggestion $suggestion, User $user): void
    {
        $this->redirectMany(collect([$suggestion]), $user);
    }

    /**
     * "301 ile birleştir" on one or many overlaps: per site one approved write (≤ 100 changes). The connector (≥ 1.9.0)
     * writes each redirect into the site's SEO plugin (Rank Math, Yoast Premium or Redirection; its own redirect
     * list only where none can) and turns the redirected page into a draft — never deleted, undoable. The suggestion
     * waits as "Siteye yazılıyor" and becomes applied only when the site confirms (writeFinished).
     *
     * @param  Collection<int, Suggestion>  $suggestions
     * @return int pages sent
     */
    public function redirectMany(Collection $suggestions, User $user): int
    {
        $errors = [];
        $bySite = [];
        foreach ($suggestions as $suggestion) {
            try {
                [$site, $change] = $this->mergeChange($suggestion);
                $bySite[(int) $site->id]['site'] = $site;
                $bySite[(int) $site->id]['items'][] = [$suggestion, $change];
            } catch (ValidationException $exception) {
                $errors[] = (string) collect($exception->errors())->flatten()->first();
            }
        }
        if ($bySite === []) {
            throw ValidationException::withMessages(['write' => $errors[0] ?? 'Birleştirilecek 301 önerisi yok.']);
        }
        $sent = 0;
        foreach ($bySite as ['site' => $site, 'items' => $items]) {
            $this->assertMergeConnector($site);
            foreach (array_chunk($items, 100) as $chunk) {
                $before = [];
                foreach ($chunk as [$suggestion]) {
                    $before[(int) $suggestion->id] = [$suggestion->status, (array) $suggestion->action];
                    // Approved first: a sync queue runs the write (and writeFinished) inside requestSiteFixes.
                    $suggestion->forceFill(['status' => Suggestion::APPROVED, 'resolved_by' => $user->id,
                        'action' => array_merge((array) $suggestion->action, ['merge_sent_at' => now()->toIso8601String(), 'merge_error' => null])])->save();
                }
                try {
                    $this->writes->requestSiteFixes($user, $site, array_map(fn (array $item): array => $item[1], $chunk), count($chunk) === 1 ? $chunk[0][0] : null);
                } catch (Throwable $exception) {
                    foreach ($chunk as [$suggestion]) {
                        [$status, $action] = $before[(int) $suggestion->id];
                        $suggestion->forceFill(['status' => $status, 'action' => $action])->save();
                    }
                    throw $exception;
                }
                foreach ($chunk as [$suggestion]) {
                    $this->memory->recordDecision($suggestion, 'onaylandı', '301 ile birleştir');
                }
                $sent += count($chunk);
            }
        }

        return $sent;
    }

    /**
     * A site write finished: every "301 ile birleştir" in it becomes applied (the site confirmed) or open again with
     * the site's error, so a merge never looks done while nothing changed on the site.
     */
    public function writeFinished(ExternalWriteAction $write): void
    {
        if ($write->action !== ExternalWriteAction::ACTION_SITE_FIX) {
            return;
        }
        $results = collect((array) data_get($write->result, 'changes', []))->keyBy('reference');
        foreach ((array) data_get($write->request_payload, 'changes', []) as $change) {
            if (($change['type'] ?? null) !== 'merge_redirect' || preg_match('/^suggestion-(\d+)-merge$/', (string) ($change['reference'] ?? ''), $match) !== 1) {
                continue;
            }
            $suggestion = Suggestion::query()->where('decision_key', self::DECISION)->find((int) $match[1]);
            if ($suggestion === null || $suggestion->status !== Suggestion::APPROVED) {
                continue;
            }
            $result = (array) $results->get($change['reference'], []);
            $action = array_merge((array) $suggestion->action, ['writes' => array_values(array_unique([...(array) (data_get($suggestion->action, 'writes') ?? []), (int) $write->id])),
                'merge_sent_at' => null]);
            if ((bool) ($result['ok'] ?? false)) {
                $suggestion->forceFill(['status' => Suggestion::APPLIED, 'applied_at' => now(), 'resolved_at' => now(),
                    'action' => array_merge($action, ['merge_error' => null, 'merge_provider' => $result['provider'] ?? null])])->save();

                continue;
            }
            $error = (string) ($result['error'] ?? $write->error ?? 'site yanıt vermedi');
            $suggestion->forceFill(['status' => Suggestion::OPEN, 'resolved_at' => null, 'action' => array_merge($action, ['merge_error' => mb_substr($error, 0, 300)])])->save();
        }
    }

    /** A merge write undone on the site: its overlaps are open work again. */
    public function writeUndone(ExternalWriteAction $write): void
    {
        if ($write->action !== ExternalWriteAction::ACTION_SITE_FIX || $write->status !== 'undone') {
            return;
        }
        foreach ((array) data_get($write->request_payload, 'changes', []) as $change) {
            if (($change['type'] ?? null) === 'merge_redirect' && preg_match('/^suggestion-(\d+)-merge$/', (string) ($change['reference'] ?? ''), $match) === 1) {
                Suggestion::query()->where('decision_key', self::DECISION)->whereKey((int) $match[1])->where('status', Suggestion::APPLIED)->first()
                    ?->forceFill(['status' => Suggestion::OPEN, 'applied_at' => null, 'resolved_at' => null, 'operator_note' => '301 geri alındı.'])->save();
            }
        }
    }

    /** "Ana sayfa bu olsun": the overlapping page becomes the cluster's main page (locked, like a manual URL choice). */
    public function makeMain(Suggestion $suggestion, User $user): void
    {
        if ($suggestion->decision_key !== self::DECISION || ! in_array($suggestion->status, [Suggestion::OPEN, Suggestion::RECHECK, Suggestion::SNOOZED], true)) {
            throw ValidationException::withMessages(['write' => 'Bu öneri zaten sonuçlandı.']);
        }
        $row = BrandClusterPage::query()->find((int) data_get($suggestion->action, 'row_id'));
        $page = Page::query()->find($suggestion->page_id);
        $brand = Brand::query()->find($suggestion->brand_id);
        if ($row === null || $page === null || $brand === null || (int) $row->website_asset_id !== (int) $page->website_asset_id) {
            throw ValidationException::withMessages(['write' => 'Küme ya da sayfa artık sitede yok.']);
        }
        app(ClusterEditor::class)->brandRow($row, ['page_id' => (int) $page->id]);
        $suggestion->forceFill(['status' => Suggestion::APPLIED, 'applied_at' => now(), 'resolved_by' => $user->id, 'resolved_at' => now(),
            'operator_note' => 'Kümenin ana sayfası bu sayfa yapıldı.'])->save();
        $this->memory->recordDecision($suggestion, 'onaylandı', 'Ana sayfa bu olsun');
        $site = $page->website;
        if ($site !== null) {
            $this->refresh($site, $brand);
        }
    }

    /** "Ayrı kalsın": the operator keeps both pages; the overlap is not proposed again until it changes. */
    public function keep(Suggestion $suggestion, User $user): void
    {
        $suggestion->forceFill(['status' => Suggestion::DISMISSED, 'resolved_by' => $user->id, 'resolved_at' => now(), 'operator_note' => 'Ayrı kalsın.'])->save();
        $this->memory->recordDecision($suggestion, 'reddedildi', 'Ayrı kalsın');
    }

    /** A merge sent to the site and not answered yet. */
    public static function mergePending(Suggestion $suggestion): bool
    {
        return $suggestion->status === Suggestion::APPROVED && filled(data_get($suggestion->action, 'merge_sent_at'));
    }

    /** "301 ile birleştir" can be pressed: a 301 recommendation that is open, or approved earlier and never sent. */
    public static function mergeable(Suggestion $suggestion): bool
    {
        return data_get($suggestion->action, 'recommendation') === self::REDIRECT && ! self::mergePending($suggestion)
            && in_array($suggestion->status, [Suggestion::OPEN, Suggestion::RECHECK, Suggestion::SNOOZED, Suggestion::APPROVED], true);
    }

    /**
     * The short reason shown under the overlapping page (Genel işler).
     *
     * @param  array<string, mixed>  $action
     */
    public static function why(array $action): string
    {
        $share = (float) ($action['share'] ?? 0);
        $recommendation = (string) ($action['recommendation'] ?? self::DIFFERENTIATE);

        return match (true) {
            $recommendation === self::REDIRECT => 'Bu kümede az trafik alıyor. Eksik bilgisi ana sayfaya taşınıp 301 ile oraya yönlendirilsin; sayfa taslağa alınır.',
            $recommendation === self::REVIEW && ($action['basis'] ?? null) === 'service' => 'Hizmet sayfası blog / soru-cevap sayfasına yönlendirilmez. Kümenin ana sayfası bu hizmet sayfası olmalı.',
            $recommendation === self::REVIEW => 'Google bu ihtiyaçta bu sayfayı gösteriyor (gösterimlerin %'.(int) round($share * 100).'si). 301 yapılmaz: ana sayfa bu olsun ya da ana sayfa güçlendirilsin.',
            ($action['basis'] ?? null) === 'location' => 'Lokasyon sayfası kendi bölgesini hedefler; birleştirilmez. Ana sayfaya bağlantı versin.',
            ($action['basis'] ?? null) === 'other_target' || $share < ClusterPageShares::CONFLICT => 'Başka bir kümenin hedef sayfası; birleştirilmez. Kendi ihtiyacına odaklansın, ana sayfaya bağlantı versin.',
            default => 'Bu kümenin gösterimlerinde payı %'.(int) round($share * 100).'. Kendi ihtiyacına odaklansın, ana sayfaya bağlantı versin.',
        };
    }

    /**
     * @return array{0: DigitalAsset, 1: array<string, mixed>} the site and the connector change of one merge
     */
    private function mergeChange(Suggestion $suggestion): array
    {
        $action = (array) $suggestion->action;
        if ($suggestion->decision_key !== self::DECISION || ($action['recommendation'] ?? null) !== self::REDIRECT) {
            throw ValidationException::withMessages(['write' => 'Bu çakışma için yönlendirme önerilmiyor.']);
        }
        if (! self::mergeable($suggestion)) {
            throw ValidationException::withMessages(['write' => self::mergePending($suggestion) ? 'Bu 301 zaten siteye gönderildi.' : 'Bu öneri zaten sonuçlandı.']);
        }
        $page = Page::query()->find($suggestion->page_id);
        $site = $page?->website;
        $main = Page::query()->find((int) ($action['main_page_id'] ?? 0));
        if ($page === null || $site === null || $main === null || (int) $main->website_asset_id !== (int) $site->id) {
            throw ValidationException::withMessages(['write' => 'Sayfalar artık sitede yok.']);
        }
        // A redirect to a page that itself redirects elsewhere would be a chain.
        $mainRedirected = Suggestion::query()->where('decision_key', self::DECISION)->where('page_id', $main->id)
            ->where(fn ($q) => $q->where('status', Suggestion::APPROVED)->orWhere(fn ($a) => $a->where('status', Suggestion::APPLIED)->whereNotNull('applied_at')))
            ->get()->contains(fn (Suggestion $s): bool => data_get($s->action, 'recommendation') === self::REDIRECT && (self::mergePending($s) || $s->status === Suggestion::APPLIED));
        if ($mainRedirected) {
            throw ValidationException::withMessages(['write' => self::path($main).' de başka bir sayfaya yönlendirildi; zincir 301 yapılmaz.']);
        }

        return [$site, [
            'type' => 'merge_redirect', 'object_id' => (int) ($page->wp_post_id ?? 0), 'from' => self::path($page), 'value' => (string) $main->url,
            'reference' => 'suggestion-'.$suggestion->id.'-merge',
        ]];
    }

    private function assertMergeConnector(DigitalAsset $site): void
    {
        try {
            $connection = app(WordPressDraftWriter::class)->connection((int) $site->id);
        } catch (Throwable $exception) {
            throw ValidationException::withMessages(['write' => $exception->getMessage()]);
        }
        $version = (string) data_get($connection->config, 'plugin_version', '0.0.0');
        $minimum = (string) config('moxdop-wordpress.merge_redirect_min_plugin_version', '1.9.0');
        if (version_compare($version, $minimum, '<')) {
            throw ValidationException::withMessages(['write' => $site->name.': WordPress Connector '.$version.'; 301 ile birleştirme (SEO eklentisine yönlendirme + sayfayı taslağa alma) için en az '
                .$minimum.' gerekli. Bağlayıcı ekranından eklentiyi güncelle.']);
        }
    }

    /**
     * @param  list<array{row: BrandClusterPage, main: Page, page: Page, share: float, recommendation: string, basis: string}>  $found
     * @return list<array{row: BrandClusterPage, main: Page, page: Page, share: float, recommendation: string, basis: string}>
     */
    private static function oneRedirectPerPage(array $found): array
    {
        $best = [];
        foreach ($found as $item) {
            if ($item['recommendation'] !== self::REDIRECT) {
                continue;
            }
            $pageId = (int) $item['page']->id;
            $rank = [self::isMoney($item['main']) ? 0 : 1, -(int) $item['row']->impressions_28d, (int) $item['row']->cluster_id];
            if (! isset($best[$pageId]) || $rank < $best[$pageId]['rank']) {
                $best[$pageId] = ['rank' => $rank, 'main' => (int) $item['main']->id];
            }
        }

        return array_values(array_filter($found, fn (array $item): bool => $item['recommendation'] !== self::REDIRECT
            || $best[(int) $item['page']->id]['main'] === (int) $item['main']->id));
    }

    /** @return array{0: string, 1: string} recommendation and its basis */
    private static function recommend(Page $main, Page $page, float $share, float $mainShare, bool $otherTarget): array
    {
        return match (true) {
            self::isMoney($page) && ! self::isMoney($main) => [self::REVIEW, 'service'],
            $share >= ClusterPageShares::LEAD && $share > $mainShare => [self::REVIEW, 'share'],
            $otherTarget => [self::DIFFERENTIATE, 'other_target'],
            $share >= ClusterPageShares::CONFLICT => [self::DIFFERENTIATE, 'traffic'],
            $page->category === 'lokasyon' && $main->category !== 'lokasyon' => [self::DIFFERENTIATE, 'location'],
            default => [self::REDIRECT, 'low'],
        };
    }

    /** @param  array{row: BrandClusterPage, main: Page, page: Page, share: float, recommendation: string, basis: string}  $item */
    private function upsert(DigitalAsset $site, Brand $brand, array $item, array $clusterRowIds): Suggestion
    {
        ['row' => $row, 'main' => $main, 'page' => $page, 'share' => $share, 'recommendation' => $recommendation, 'basis' => $basis] = $item;
        $mainPath = self::path($main);
        $path = self::path($page);
        $why = match ($basis) {
            'service' => $path.' bir hizmet sayfası; '.$mainPath.' blog / soru-cevap sayfasına 301 ile yönlendirilmez. Kümenin ana sayfası '.$path.' olmalı.',
            'share' => $path.' bu kümenin gösterimlerinin %'.(int) round($share * 100).'sini alıyor; ana sayfa '.$mainPath.' değil bu sayfa olmalı ya da '.$mainPath.' güçlendirilmeli. 301 yapılmaz.',
            'other_target' => $path.' başka bir kümenin hedef sayfası; birleştirilmez. Bu kümeye değil kendi ihtiyacına odaklansın, '.$mainPath.' sayfasına bağlantı versin.',
            'traffic' => $path.' bu kümede trafik alıyor (gösterimlerin %'.(int) round($share * 100).'si); '.$mainPath.' ile aynı ihtiyacı hedefliyor. Farklı bir ihtiyaca odaklanmalı ve '.$mainPath.' sayfasına bağlantı vermeli.',
            'location' => $path.' bir lokasyon sayfası; kendi bölgesini hedeflesin, '.$mainPath.' sayfasına bağlantı versin.',
            default => $path.' aynı ihtiyacı işliyor ama bu kümede az trafik alıyor. Eksik bilgisi '.$mainPath.' sayfasına taşınıp 301 ile oraya yönlendirilmeli; sayfa taslağa alınır.',
        };
        $fingerprint = hash('sha256', implode('|', [$brand->id, 'cluster-overlap', $row->cluster_id, $main->id, $page->id]));
        $suggestion = Suggestion::query()->where('brand_id', $brand->id)->where('fingerprint', $fingerprint)->first()
            ?? $this->legacy($brand, $clusterRowIds, $page) ?? new Suggestion;
        $material = hash('sha256', $recommendation.'|'.$main->id);
        $gone = $suggestion->status === Suggestion::APPLIED && $suggestion->applied_at === null && $suggestion->operator_note === self::GONE;
        // An approval that was never sent (old "Onaylandı · uygulanacak") follows the new recommendation too.
        $approvedUnsent = $suggestion->status === Suggestion::APPROVED && ! self::mergePending($suggestion);
        $reopen = $gone || ((in_array($suggestion->status, [Suggestion::DISMISSED, Suggestion::APPLIED], true) || $approvedUnsent) && $suggestion->material_hash !== $material);
        $suggestion->forceFill([
            'brand_id' => $brand->id, 'channel' => 'search', 'decision_key' => self::DECISION, 'fingerprint' => $fingerprint, 'material_hash' => $material,
            'title' => mb_substr('Çakışma: «'.$row->cluster->name.'» · '.$path.' ↔ '.$mainPath, 0, 160), 'reason' => mb_substr($why, 0, 240),
            'priority' => $recommendation === self::REDIRECT ? 2 : 3,
            'evidence' => [['kind' => 'overlap', 'value' => $why, 'source' => $share > 0 ? 'Search Console + sayfa içeriği' : 'sayfa içeriği']],
            'action_type' => self::TYPE, 'target_type' => 'page', 'target_id' => (int) $page->id, 'page_id' => (int) $page->id, 'cluster_id' => (int) $row->cluster_id,
            'action' => array_merge((array) $suggestion->action, ['site_id' => (int) $site->id, 'row_id' => (int) $row->id, 'main_page_id' => (int) $main->id,
                'main_url' => (string) $main->url, 'overlap_url' => (string) $page->url, 'recommendation' => $recommendation, 'basis' => $basis, 'share' => $share]),
            'status' => $suggestion->exists && ! $reopen ? $suggestion->status : Suggestion::OPEN,
            'first_seen_at' => $suggestion->first_seen_at ?? now(), 'last_seen_at' => now(),
        ] + ($gone ? ['operator_note' => null, 'resolved_at' => null] : []))->save();

        return $suggestion;
    }

    /**
     * Before 2026-10-05 the fingerprint carried the language row, so one pair could have a suggestion per row. The one
     * the operator decided on (merged, kept apart, snoozed) moves over to the new fingerprint; the others close as gone.
     *
     * @param  list<int>  $clusterRowIds
     */
    private function legacy(Brand $brand, array $clusterRowIds, Page $page): ?Suggestion
    {
        $fingerprints = array_map(fn (int $rowId): string => hash('sha256', implode('|', [$brand->id, 'cluster-overlap', $rowId, $page->id])), $clusterRowIds);

        return Suggestion::query()->where('brand_id', $brand->id)->whereIn('fingerprint', $fingerprints)->orderBy('id')->get()
            ->sortBy(fn (Suggestion $s): int => match (true) {
                $s->status === Suggestion::DISMISSED, $s->status === Suggestion::APPLIED && $s->applied_at !== null => 0,
                $s->status === Suggestion::SNOOZED => 1,
                default => 2,
            })->first();
    }

    /**
     * The row's language (or, on a language-less row, its main page's) and the page's must match when both are known,
     * and so must the language prefix of the paths ("/en/…" ↔ "/…"): a TR page ↔ EN page is a translation, not an overlap.
     */
    private static function sameLanguage(string $language, Page $main, Page $page): bool
    {
        $language = strtolower($language);
        $pageLanguage = strtolower((string) $page->language);
        if (self::pathLanguage($main) !== self::pathLanguage($page)) {
            return false;
        }

        return $language === '' || $pageLanguage === '' || $language === $pageLanguage;
    }

    /** "/en/what-is/" → "en"; "" without a language segment. */
    private static function pathLanguage(Page $page): string
    {
        $first = explode('/', trim(self::path($page), '/'))[0];

        return preg_match('/^[a-z]{2}(-[a-z]{2})?$/i', $first) === 1 ? strtolower($first) : '';
    }

    /** Home and language home pages, contact / about / legal pages. */
    private static function never(Page $page): bool
    {
        $path = '/'.trim(self::path($page), '/');

        return $path === '/' || self::pathLanguage($page) !== '' && substr_count(trim($path, '/'), '/') === 0
            || preg_match(ClusterAudit::NEVER_CANDIDATE, $path) === 1;
    }

    /** A page tied (adım 1) only to other services of the brand answers another need. @param  list<int>  $services */
    private static function otherService(array $services, int $serviceId): bool
    {
        return $services !== [] && ! in_array($serviceId, $services, true);
    }

    private static function isMoney(Page $page): bool
    {
        return in_array($page->category, self::MONEY, true)
            || ($page->category === null && SitePagesReader::pathCategory(self::path($page)) === 'hizmet');
    }

    private static function path(Page $page): string
    {
        return '/'.ltrim((string) ($page->path ?: SeoText::urlPath((string) $page->url)), '/');
    }
}
