<?php

namespace App\Services\Site;

use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\SeoTasks\SeoText;
use Illuminate\Validation\ValidationException;

/**
 * Bir küme sitede birden fazla sayfayla eşleşirse: the main page of the cluster row plus the other pages that answer
 * the same need — the AI match's `also_page_ids` (content) and the pages Google shows with ≥ CONFLICT of the cluster's
 * Search Console impressions. Every overlapping page becomes one suggestion in the work list (`site.cluster_overlap`)
 * with a rule recommendation:
 * - redirect ("301 ile birleştir"): the page gets little of the cluster's traffic and is no other cluster's target —
 *   its content goes into the main page, the URL redirects there (approved WordPress write, ADR-070, undoable);
 * - differentiate ("Ayrıştır"): the page gets real traffic or is the target of another cluster — it is kept and
 *   focused on its own need, with a link to the main page.
 * Overlaps that are gone close their suggestion. Operator-added pages (extra_page_ids) are never an overlap. Cluster rows are
 * per language: a page in another language than the row is a language version, never an overlap, and the same
 * cluster · main page · page pair is one suggestion whichever language row found it.
 */
final class ClusterOverlaps
{
    public const string DECISION = 'site.cluster_overlap';

    public const string TYPE = 'cluster_overlap';

    public const string REDIRECT = 'redirect';

    public const string DIFFERENTIATE = 'differentiate';

    /** Note of an overlap the system closed because it was gone; it reopens when the overlap comes back. */
    public const string GONE = 'Çakışma kalktı.';

    public function __construct(private readonly ExternalWriteService $writes, private readonly BrandMemoryService $memory) {}

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
        $pages = Page::query()->where('website_asset_id', $site->id)->get(['id', 'url', 'path', 'language'])->keyBy('id');
        $byKey = $pages->mapWithKeys(fn (Page $p): array => [SeoText::urlKey((string) $p->url) => (int) $p->id])->all();
        $targets = BrandClusterPage::query()->where('website_asset_id', $site->id)->where('excluded', false)->whereNotNull('page_id')
            ->get(['cluster_id', 'page_id'])->groupBy('page_id')->map(fn ($group): array => $group->pluck('cluster_id')->map(fn ($id): int => (int) $id)->all())->all();
        $kept = [];
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
                if ($page === null || in_array($pageId, $skip, true) || ! self::sameLanguage((string) ($row->language ?: $main->language), $page)) {
                    continue;
                }
                $share = $gsc[$pageId] ?? 0.0;
                $otherTarget = array_diff($targets[$pageId] ?? [], [(int) $row->cluster_id]) !== [];
                $suggestion = $this->upsert($site, $brand, $row, $main, $page, $share, $otherTarget, $rowIds[(int) $row->cluster_id] ?? [(int) $row->id]);
                $kept[(int) $suggestion->id] = in_array($suggestion->status, [Suggestion::OPEN, Suggestion::RECHECK], true);
            }
        }
        Suggestion::query()->where('brand_id', $brand->id)->where('decision_key', self::DECISION)
            ->whereIn('status', [Suggestion::OPEN, Suggestion::RECHECK, Suggestion::SNOOZED])->whereNotIn('id', array_keys($kept) ?: [0])->get()
            ->filter(fn (Suggestion $s): bool => (int) data_get($s->action, 'site_id') === (int) $site->id)
            ->each(fn (Suggestion $s) => $s->forceFill(['status' => Suggestion::APPLIED, 'resolved_at' => now(), 'operator_note' => self::GONE])->save());

        return count(array_filter($kept));
    }

    /** "301 ile birleştir": the overlapping page redirects to the cluster's main page (approved WordPress write, undoable). */
    public function redirect(Suggestion $suggestion, User $user): void
    {
        $action = (array) $suggestion->action;
        if ($suggestion->decision_key !== self::DECISION || ($action['recommendation'] ?? null) !== self::REDIRECT) {
            throw ValidationException::withMessages(['write' => 'Bu çakışma için yönlendirme önerilmiyor; sayfa ayrıştırılmalı.']);
        }
        if (! in_array($suggestion->status, [Suggestion::OPEN, Suggestion::RECHECK], true)) {
            throw ValidationException::withMessages(['write' => 'Bu öneri zaten sonuçlandı.']);
        }
        $page = Page::query()->find($suggestion->page_id);
        $site = $page?->website;
        $main = Page::query()->find((int) ($action['main_page_id'] ?? 0));
        if ($page === null || $site === null || $main === null || (int) $main->website_asset_id !== (int) $site->id) {
            throw ValidationException::withMessages(['write' => 'Sayfalar artık sitede yok.']);
        }
        $from = '/'.ltrim((string) ($page->path ?: SeoText::urlPath((string) $page->url)), '/');
        $write = $this->writes->requestSiteFixes($user, $site, [[
            'type' => 'redirect', 'from' => $from, 'value' => (string) $main->url, 'reference' => 'suggestion-'.$suggestion->id.'-redirect',
        ]], $suggestion);
        $suggestion->forceFill([
            'status' => Suggestion::APPLIED, 'applied_at' => now(), 'resolved_by' => $user->id, 'resolved_at' => now(),
            'action' => array_merge($action, ['writes' => [...(array) ($action['writes'] ?? []), (int) $write->id]]),
        ])->save();
        $this->memory->recordDecision($suggestion, 'onaylandı', '301 ile birleştir');
    }

    /** "Ayrı kalsın": the operator keeps both pages; the overlap is not proposed again until it changes. */
    public function keep(Suggestion $suggestion, User $user): void
    {
        $suggestion->forceFill(['status' => Suggestion::DISMISSED, 'resolved_by' => $user->id, 'resolved_at' => now(), 'operator_note' => 'Ayrı kalsın.'])->save();
        $this->memory->recordDecision($suggestion, 'reddedildi', 'Ayrı kalsın');
    }

    /** @param  list<int>  $clusterRowIds  the site's rows of the same cluster (their old per-row fingerprints) */
    private function upsert(DigitalAsset $site, Brand $brand, BrandClusterPage $row, Page $main, Page $page, float $share, bool $otherTarget, array $clusterRowIds): Suggestion
    {
        $recommendation = $share >= ClusterPageShares::CONFLICT || $otherTarget ? self::DIFFERENTIATE : self::REDIRECT;
        $mainPath = self::path($main);
        $path = self::path($page);
        $why = match (true) {
            $otherTarget => $path.' başka bir kümenin hedef sayfası; birleştirilmez. Bu kümeye değil kendi ihtiyacına odaklansın, '.$mainPath.' sayfasına bağlantı versin.',
            $recommendation === self::DIFFERENTIATE => $path.' bu kümede trafik alıyor (gösterimlerin %'.(int) round($share * 100).'si); '.$mainPath.' ile aynı ihtiyacı hedefliyor. Farklı bir ihtiyaca odaklanmalı ve '.$mainPath.' sayfasına bağlantı vermeli.',
            default => $path.' aynı ihtiyacı işliyor ama bu kümede az trafik alıyor. Eksik bilgisi '.$mainPath.' sayfasına taşınıp 301 ile oraya yönlendirilmeli.',
        };
        $fingerprint = hash('sha256', implode('|', [$brand->id, 'cluster-overlap', $row->cluster_id, $main->id, $page->id]));
        $suggestion = Suggestion::query()->where('brand_id', $brand->id)->where('fingerprint', $fingerprint)->first()
            ?? $this->legacy($brand, $clusterRowIds, $page) ?? new Suggestion;
        $material = hash('sha256', $recommendation.'|'.$main->id);
        $gone = $suggestion->status === Suggestion::APPLIED && $suggestion->applied_at === null && $suggestion->operator_note === self::GONE;
        $reopen = $gone || (in_array($suggestion->status, [Suggestion::DISMISSED, Suggestion::APPLIED], true) && $suggestion->material_hash !== $material);
        $suggestion->forceFill([
            'brand_id' => $brand->id, 'channel' => 'search', 'decision_key' => self::DECISION, 'fingerprint' => $fingerprint, 'material_hash' => $material,
            'title' => mb_substr('Çakışma: «'.$row->cluster->name.'» · '.$path.' ↔ '.$mainPath, 0, 160), 'reason' => mb_substr($why, 0, 240),
            'priority' => $recommendation === self::REDIRECT ? 2 : 3,
            'evidence' => [['kind' => 'overlap', 'value' => $why, 'source' => $share > 0 ? 'Search Console + sayfa içeriği' : 'sayfa içeriği']],
            'action_type' => self::TYPE, 'target_type' => 'page', 'target_id' => (int) $page->id, 'page_id' => (int) $page->id, 'cluster_id' => (int) $row->cluster_id,
            'action' => array_merge((array) $suggestion->action, ['site_id' => (int) $site->id, 'row_id' => (int) $row->id, 'main_page_id' => (int) $main->id,
                'main_url' => (string) $main->url, 'overlap_url' => (string) $page->url, 'recommendation' => $recommendation, 'share' => $share]),
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
     * The row's language (or, on a language-less row, its main page's) and the page's must match when both are known:
     * a TR row ↔ EN page is a translation, not an overlap.
     */
    private static function sameLanguage(string $language, Page $page): bool
    {
        $language = strtolower($language);
        $pageLanguage = strtolower((string) $page->language);

        return $language === '' || $pageLanguage === '' || $language === $pageLanguage;
    }

    private static function path(Page $page): string
    {
        return '/'.ltrim((string) ($page->path ?: SeoText::urlPath((string) $page->url)), '/');
    }
}
