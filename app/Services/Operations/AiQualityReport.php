<?php

namespace App\Services\Operations;

use App\Ai\Agents\WhatsAppReplyAgent;
use App\Models\AiProduction;
use App\Services\Archive\ProductionArchive;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI kalitesi: how much of what AI produced was used. For every AI-producing source over a window — the
 * production archive (advisor copy drafts, SEO briefs, review replies, report commentary, insights, page drafts…),
 * AI site-fix proposals and Brain proposals — produced, accepted, accepted after an edit, rejected, still waiting,
 * and the recorded AI cost of the source's route. Outcomes come from the operator's own decision on the work item
 * (advisor item done / skipped, fix applied / dismissed, review reply posted, report published…); an archive mark
 * (used / published / discarded, 👍 / 👎) wins over the derived one. A version replaced by a newer one without
 * being used counts as rejected.
 *
 * Acceptance = accepted / (accepted + rejected). A source is flagged when that is below 30 % over ≥ 20 decided items.
 */
final class AiQualityReport
{
    public const float LOW_ACCEPTANCE = 30.0;

    public const int MIN_DECIDED = 20;

    /** archive kind → AI route key (for cost) */
    private const array KIND_ROUTES = [
        'google_ads.ad_copy' => AiRouteKeys::GOOGLE_ADS_AD_COPY_DRAFT,
        'meta_ads.creative' => AiRouteKeys::META_ADS_CREATIVE_DRAFT,
        'gbp.profile' => AiRouteKeys::GBP_PROFILE_DRAFT,
        'seo.content_brief' => AiRouteKeys::SEO_TASKS_CONTENT_PLANNER,
        'whatsapp.reply' => WhatsAppReplyAgent::ROUTE,
        'brand_setup.proposal' => AiRouteKeys::BRAND_SETUP,
        'report.monthly_commentary' => AiRouteKeys::MONTHLY_REPORT_COMMENTARY,
        'gbp.review_reply' => AiRouteKeys::GBP_REVIEW_REPLY,
        'gbp.post' => AiRouteKeys::GBP_POST_DRAFT,
        'advisor.explain' => AiRouteKeys::INSIGHT_ADVISOR_EXPLAIN,
        'google_ads.search_term_triage' => AiRouteKeys::INSIGHT_SEARCH_TERM_TRIAGE,
        'meta.geo_results' => AiRouteKeys::INSIGHT_META_GEO,
        'reviews.themes' => AiRouteKeys::INSIGHT_REVIEW_THEMES,
        'alerts.cause' => AiRouteKeys::INSIGHT_ALERT_CAUSE,
        'google_ads.landing_fit' => AiRouteKeys::INSIGHT_LANDING_FIT,
        'customer.brief' => AiRouteKeys::INSIGHT_CUSTOMER_BRIEF,
        'sales.lead_score' => AiRouteKeys::INSIGHT_LEAD_SCORE,
        'website.technical_tasks' => AiRouteKeys::INSIGHT_TECHNICAL_TASKS,
        'website.page_draft' => AiRouteKeys::SITE_FIX_PAGE,
        'content.localized' => AiRouteKeys::CONTENT_LOCALIZE,
    ];

    /** Brain proposal kind → AI route key (rule / vector proposals cost nothing). */
    private const array BRAIN_ROUTES = [
        'query_service' => AiRouteKeys::BRAIN_QUERY_CLASSIFIER,
        'account_mapping' => AiRouteKeys::BRAIN_ACCOUNT_MAPPING,
        'service_clusters' => AiRouteKeys::BRAIN_CLUSTER_LABELS,
        'meta_ad_services' => AiRouteKeys::BRAIN_CREATIVE_CLASSIFIER,
        'page_features' => AiRouteKeys::BRAIN_PAGE_FEATURES,
    ];

    private const array BRAIN_LABELS = [
        'query_service' => 'Sorgu → hizmet eşleştirme', 'account_mapping' => 'Hesap → marka eşleştirme', 'service_clusters' => 'Hizmet kümeleri',
        'cluster_targets' => 'Küme hedef sayfaları', 'matching_keyword' => 'Eşleşme anahtar kelimeleri', 'meta_ad_services' => 'Meta reklam → hizmet',
        'page_features' => 'Sayfa özellikleri',
    ];

    private const array FIX_LABELS = [
        'seo_title' => 'SEO başlığı', 'seo_description' => 'Meta açıklama', 'alt_text' => 'Görsel alt metni', 'internal_link' => 'İç bağlantı', 'schema' => 'Yapılandırılmış veri',
    ];

    /**
     * @return array{days: int, groups: list<array{key: string, label: string, rows: list<array<string, mixed>>, cost: float}>, routes: list<array{route: string, calls: int, cost: float, mapped: bool}>, total_cost: float, flagged: int}
     */
    public function build(int $days): array
    {
        $days = in_array($days, [30, 90], true) ? $days : 30;
        $since = now()->subDays($days);
        $costs = $this->routeCosts($since);
        $mapped = [];
        $groups = [];

        foreach ([
            ['archive', 'Üretim arşivi (taslaklar, metinler, analizler)', $this->archiveRows($since), fn (string $source): array => isset(self::KIND_ROUTES[$source]) ? [self::KIND_ROUTES[$source]] : []],
            // Values and links are produced in batches covering several fix types: their cost is shown on the group only.
            ['site_fix', 'Site düzeltmesi önerileri (AI)', $this->siteFixRows($since), fn (string $source): array => []],
            ['brain', 'Beyin önerileri', $this->brainRows($since), fn (string $source): array => isset(self::BRAIN_ROUTES[$source]) ? [self::BRAIN_ROUTES[$source]] : []],
        ] as [$key, $label, $rows, $routesOf]) {
            $groupRoutes = $key === 'site_fix' ? [AiRouteKeys::SITE_FIX_VALUES, AiRouteKeys::SITE_FIX_LINKS] : [];
            foreach ($rows as &$row) {
                $routes = $routesOf((string) $row['source']);
                $row['cost'] = $row['is_total'] && $routes !== [] ? round(array_sum(array_map(fn (string $r): float => $costs[$r]['cost'] ?? 0.0, $routes)), 4) : null;
                array_push($groupRoutes, ...$routes);
            }
            unset($row);
            $groupRoutes = array_values(array_unique($groupRoutes));
            foreach ($groupRoutes as $route) {
                $mapped[$route] = true;
            }
            $groups[] = ['key' => $key, 'label' => $label, 'rows' => $rows, 'cost' => round(array_sum(array_map(fn (string $r): float => $costs[$r]['cost'] ?? 0.0, $groupRoutes)), 4)];
        }

        $routes = collect($costs)->map(fn (array $c, string $route): array => ['route' => $route, 'calls' => $c['calls'], 'cost' => $c['cost'], 'mapped' => isset($mapped[$route])])
            ->sortByDesc('cost')->values()->all();

        return [
            'days' => $days,
            'groups' => $groups,
            'routes' => $routes,
            'total_cost' => round(array_sum(array_column($routes, 'cost')), 4),
            'flagged' => collect($groups)->flatMap(fn (array $g): array => $g['rows'])->where('is_total', true)->where('flagged', true)->count(),
        ];
    }

    /** @return list<array<string, mixed>> one total row per kind, then its prompt versions */
    private function archiveRows(\DateTimeInterface $since): array
    {
        if (! Schema::hasTable('ai_productions')) {
            return [];
        }
        $productions = AiProduction::query()->where('created_at', '>=', $since)
            ->get(['id', 'kind', 'subject_type', 'subject_id', 'version', 'content', 'prompt_version', 'status', 'rating', 'created_at']);
        if ($productions->isEmpty()) {
            return [];
        }
        // Latest version per subject over all time, so a version replaced after the window still counts as replaced.
        $latest = AiProduction::query()->whereIn('subject_id', $productions->pluck('subject_id')->unique())
            ->selectRaw('kind, subject_type, subject_id, max(version) as v')->groupBy('kind', 'subject_type', 'subject_id')->get()
            ->mapWithKeys(fn (object $r): array => [$r->kind.'|'.$r->subject_type.'|'.$r->subject_id => (int) $r->v])->all();
        $subjects = $this->subjects($productions);

        $out = [];
        foreach ($productions->groupBy('kind') as $kind => $rows) {
            $tallies = ['_all' => $this->emptyTally()];
            foreach ($rows as $production) {
                $isLatest = ($latest[$production->kind.'|'.$production->subject_type.'|'.$production->subject_id] ?? $production->version) <= $production->version;
                $outcome = $this->archiveOutcome($production, $isLatest, $subjects);
                $version = (string) ($production->prompt_version ?: '—');
                $tallies[$version] ??= $this->emptyTally();
                $this->add($tallies[$version], $outcome);
                $this->add($tallies['_all'], $outcome);
            }
            $out = array_merge($out, $this->rows((string) $kind, ProductionArchive::KIND_LABELS[$kind] ?? (string) $kind, $tallies));
        }

        return $this->sortRows($out);
    }

    /**
     * accepted | edited | rejected | pending
     *
     * @param  array<string, array<int, object>>  $subjects
     */
    private function archiveOutcome(AiProduction $production, bool $isLatest, array $subjects): string
    {
        if (in_array($production->status, [AiProduction::STATUS_USED, AiProduction::STATUS_PUBLISHED], true)) {
            return 'accepted';
        }
        if ($production->status === AiProduction::STATUS_DISCARDED) {
            return 'rejected';
        }
        $derived = $isLatest ? $this->derived($production, $subjects[$production->subject_type][$production->subject_id] ?? null) : 'rejected';
        if ($derived !== 'pending') {
            return $derived;
        }

        return match ((int) $production->rating) {
            1 => 'accepted',
            -1 => 'rejected',
            default => 'pending',
        };
    }

    private function derived(AiProduction $production, ?object $subject): string
    {
        if ($subject === null) {
            return 'pending';
        }

        return match ($production->subject_type) {
            'AdvisorItem' => match ((string) $subject->status) {
                'done' => 'accepted',
                'skipped' => $subject->snoozed_until === null ? 'rejected' : 'pending',
                default => 'pending',
            },
            'SeoTask' => match ((string) $subject->status) {
                'done' => 'accepted',
                'skipped' => 'rejected',
                default => 'pending',
            },
            'MonthlyReport' => in_array((string) $subject->status, ['published', 'sent'], true) || $subject->published_at !== null
                ? ((json_decode((string) $subject->commentary, true)['source'] ?? null) === 'operator' ? 'edited' : 'accepted')
                : 'pending',
            'BrandSetupProposal' => (string) $subject->status === 'applied' ? 'accepted' : 'pending',
            'SiteFixItem' => match ((string) $subject->status) {
                'applied', 'queued', 'drafted' => $subject->proposed_by === 'operator' ? 'edited' : 'accepted',
                'dismissed', 'undone' => 'rejected',
                default => 'pending',
            },
            'GbpReview' => $this->reviewOutcome($production, $subject),
            default => 'pending',
        };
    }

    /** A reply posted after the draft: accepted, or edited when the posted text differs from the draft. */
    private function reviewOutcome(AiProduction $production, object $review): string
    {
        $reply = json_decode((string) $review->review_reply, true);
        $posted = trim((string) ($reply['comment'] ?? ''));
        if ($posted === '') {
            return 'pending';
        }
        $postedAt = $reply['updateTime'] ?? $reply['update_time'] ?? null;
        if ($postedAt !== null && strtotime((string) $postedAt) < $production->created_at->getTimestamp()) {
            return 'pending';
        }
        $normalize = static fn (string $text): string => preg_replace('/\s+/u', ' ', mb_strtolower(trim($text))) ?? '';

        return $normalize($posted) === $normalize((string) ($production->content['reply'] ?? '')) ? 'accepted' : 'edited';
    }

    /**
     * @param  Collection<int, AiProduction>  $productions
     * @return array<string, array<int, object>>
     */
    private function subjects(Collection $productions): array
    {
        $tables = [
            'AdvisorItem' => ['advisor_items', ['id', 'status', 'snoozed_until']],
            'SeoTask' => ['seo_tasks', ['id', 'status']],
            'MonthlyReport' => ['monthly_reports', ['id', 'status', 'published_at', 'commentary']],
            'BrandSetupProposal' => ['brand_setup_proposals', ['id', 'status']],
            'SiteFixItem' => ['site_fix_items', ['id', 'status', 'proposed_by']],
            'GbpReview' => ['gbp_reviews', ['id', 'review_reply']],
        ];
        $out = [];
        foreach ($productions->groupBy('subject_type') as $type => $rows) {
            if (! isset($tables[$type]) || ! Schema::hasTable($tables[$type][0])) {
                continue;
            }
            [$table, $columns] = $tables[$type];
            foreach ($rows->pluck('subject_id')->unique()->chunk(500) as $ids) {
                foreach (DB::table($table)->whereIn('id', $ids->all())->get($columns) as $subject) {
                    $out[$type][(int) $subject->id] = $subject;
                }
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> AI proposals for WordPress fixes (page drafts are in the archive) */
    private function siteFixRows(\DateTimeInterface $since): array
    {
        if (! Schema::hasTable('site_fix_items')) {
            return [];
        }
        $items = DB::table('site_fix_items')->where('created_at', '>=', $since)->whereIn('proposed_by', ['ai', 'operator'])
            ->get(['id', 'type', 'status', 'proposed_by', 'proposed']);
        $byType = [];
        foreach ($items as $item) {
            $proposed = json_decode((string) $item->proposed, true) ?: [];
            // Only proposals that came from AI (an operator edit keeps the AI prompt version); page drafts are archive rows.
            if (! isset($proposed['prompt_version']) || isset($proposed['value']['html'])) {
                continue;
            }
            $outcome = match ((string) $item->status) {
                'applied', 'queued', 'drafted' => $item->proposed_by === 'operator' ? 'edited' : 'accepted',
                'dismissed', 'undone' => 'rejected',
                default => 'pending',
            };
            $byType[(string) $item->type]['_all'] ??= $this->emptyTally();
            $version = (string) $proposed['prompt_version'];
            $byType[(string) $item->type][$version] ??= $this->emptyTally();
            $this->add($byType[(string) $item->type]['_all'], $outcome);
            $this->add($byType[(string) $item->type][$version], $outcome);
        }
        $out = [];
        foreach ($byType as $type => $tallies) {
            $out = array_merge($out, $this->rows($type, self::FIX_LABELS[$type] ?? $type, $tallies));
        }

        return $this->sortRows($out);
    }

    /** @return list<array<string, mixed>> Brain proposals per kind, versions = how they were made (ai / vector / rule) */
    private function brainRows(\DateTimeInterface $since): array
    {
        if (! Schema::hasTable('brain_proposals')) {
            return [];
        }
        $rows = DB::table('brain_proposals')->where('created_at', '>=', $since)->where('status', '!=', 'nothing')
            ->selectRaw('kind, source, status, count(*) as c')->groupBy('kind', 'source', 'status')->get();
        $byKind = [];
        foreach ($rows as $row) {
            $outcome = match ((string) $row->status) {
                'applied' => 'accepted',
                'rejected' => 'rejected',
                'stale' => 'rejected',
                default => 'pending',
            };
            $byKind[(string) $row->kind]['_all'] ??= $this->emptyTally();
            $byKind[(string) $row->kind][(string) $row->source] ??= $this->emptyTally();
            $this->add($byKind[(string) $row->kind]['_all'], $outcome, (int) $row->c);
            $this->add($byKind[(string) $row->kind][(string) $row->source], $outcome, (int) $row->c);
        }
        $out = [];
        foreach ($byKind as $kind => $tallies) {
            $out = array_merge($out, $this->rows($kind, self::BRAIN_LABELS[$kind] ?? $kind, $tallies));
        }

        return $this->sortRows($out);
    }

    /** @return array<string, array{calls: int, cost: float}> */
    private function routeCosts(\DateTimeInterface $since): array
    {
        if (! Schema::hasTable('ai_usage_records')) {
            return [];
        }

        return DB::table('ai_usage_records')->where('created_at', '>=', $since)
            ->selectRaw('route_key, count(*) as calls, sum(cost_usd) as cost')->groupBy('route_key')->get()
            ->mapWithKeys(fn (object $r): array => [(string) ($r->route_key ?? 'bilinmiyor') => ['calls' => (int) $r->calls, 'cost' => round((float) $r->cost, 4)]])->all();
    }

    /** @return array{produced: int, accepted: int, edited: int, rejected: int, pending: int} */
    private function emptyTally(): array
    {
        return ['produced' => 0, 'accepted' => 0, 'edited' => 0, 'rejected' => 0, 'pending' => 0];
    }

    /** @param array{produced: int, accepted: int, edited: int, rejected: int, pending: int} $tally */
    private function add(array &$tally, string $outcome, int $count = 1): void
    {
        $tally['produced'] += $count;
        $tally[$outcome] += $count;
    }

    /**
     * @param  array<string, array{produced: int, accepted: int, edited: int, rejected: int, pending: int}>  $tallies  `_all` + per version
     * @return list<array<string, mixed>>
     */
    private function rows(string $source, string $label, array $tallies): array
    {
        $out = [];
        foreach ($tallies as $version => $tally) {
            $used = $tally['accepted'] + $tally['edited'];
            $decided = $used + $tally['rejected'];
            $rate = $decided > 0 ? round($used / $decided * 100, 1) : null;
            $out[] = $tally + [
                'source' => $source, 'label' => $label, 'version' => $version === '_all' ? null : $version, 'is_total' => $version === '_all',
                'decided' => $decided, 'acceptance' => $rate,
                'flagged' => $rate !== null && $decided >= self::MIN_DECIDED && $rate < self::LOW_ACCEPTANCE,
            ];
        }
        if (count($out) === 2) {
            // One version only: the version row repeats the total.
            $out[0]['version'] = $out[1]['version'];
            array_pop($out);
        }

        return $out;
    }

    /**
     * Sources by volume, each total row followed by its versions.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sortRows(array $rows): array
    {
        return collect($rows)->groupBy('source')->sortByDesc(fn (Collection $g): int => (int) $g->firstWhere('is_total', true)['produced'])
            ->flatMap(fn (Collection $g): array => $g->sortBy(fn (array $r): string => ($r['is_total'] ? '0' : '1').$r['version'])->values()->all())->values()->all();
    }
}
