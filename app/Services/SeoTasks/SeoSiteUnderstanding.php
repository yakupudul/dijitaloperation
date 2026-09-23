<?php

namespace App\Services\SeoTasks;

use App\Ai\Agents\SeoSiteUnderstandingAgent;
use App\Models\SeoPlan;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Step A2 — when the Brand has no services, infer them from the site's own stored data so the plan
 * can still suggest content. Order: recent cached inference → one AI call → deterministic page topics.
 *
 * Inferred services are plan-local (ids "inferred:<slug>") and are never written to the Brand;
 * the operator may adopt them from the website SEO tab.
 */
final class SeoSiteUnderstanding
{
    public const string SOURCE_BRAND = 'brand';

    public const string SOURCE_AI = 'ai';

    public const string SOURCE_RULES = 'rules';

    public const string SOURCE_CACHE = 'cache';

    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
    ) {}

    /**
     * @return array{offerings: list<array<string, mixed>>, understanding: array<string, mixed>|null, calls: int}
     */
    public function resolve(SeoPlan $plan, array $input, bool $useAi = true): array
    {
        $reason = 'brand_has_no_services';
        if (($input['offerings'] ?? []) !== []) {
            if ($this->servicesFitSite($input)) {
                return ['offerings' => $input['offerings'], 'understanding' => null, 'calls' => 0];
            }
            $reason = 'brand_services_not_on_site';
        }
        $input['offerings'] = [];

        $cached = $this->cached($plan);
        if ($cached !== null) {
            $cached['source_detail'] = $cached['source'];
            $cached['source'] = self::SOURCE_CACHE;
            $cached['reason'] = $reason;

            return ['offerings' => $this->toOfferings($cached['services'], $input), 'understanding' => $cached, 'calls' => 0];
        }

        $calls = 0;
        $understanding = null;
        if ($useAi && (bool) config('moxdop-seo-tasks.llm.enabled', true)) {
            [$understanding, $calls] = $this->fromAi($plan, $input);
        }
        if ($understanding === null || $understanding['services'] === []) {
            $fallback = $this->fromRules($input);
            $fallback['ai_skipped_reason'] = $understanding['skipped_reason'] ?? ($understanding === null ? 'unavailable' : 'no_services');
            $understanding = $fallback;
        }
        $understanding['generated_at'] = now()->toIso8601String();
        $understanding['reason'] = $reason;

        return ['offerings' => $this->toOfferings($understanding['services'], $input), 'understanding' => $understanding, 'calls' => $calls];
    }

    /**
     * A Brand can own several websites. When this site's own search data and pages show none of the
     * Brand's services (e.g. the agency's own site under a clinic Brand), the services do not
     * describe the site and must not drive its plan.
     */
    public function servicesFitSite(array $input): bool
    {
        $rows = $input['gsc']['rows'] ?? [];
        $totalImpressions = array_sum(array_column($rows, 'impressions'));
        $minImpressions = SeoTaskConfig::int('understanding.fit_min_impressions', 200);
        if ($totalImpressions < $minImpressions) {
            return true; // not enough evidence to overrule the operator's services
        }

        $phrases = [];
        $portfolio = [];
        foreach ($input['offerings'] as $offering) {
            foreach (array_merge([$offering['name']], $offering['names'] ?? [], $offering['keywords'] ?? []) as $phrase) {
                if (is_string($phrase) && mb_strlen(trim($phrase)) >= 3) {
                    $phrases[SeoText::fold($phrase)] = $phrase;
                }
            }
            foreach ($offering['queries'] ?? [] as $query) {
                $portfolio[mb_strtolower($query)] = true;
            }
        }

        foreach ($input['pages'] ?? [] as $page) {
            foreach ($phrases as $phrase) {
                foreach ([$page['title'] ?? null, $page['h1'] ?? null] as $text) {
                    if (is_string($text) && SeoText::containsPhrase($text, $phrase)) {
                        return true;
                    }
                }
            }
        }

        $matched = 0;
        foreach ($rows as $row) {
            if (isset($portfolio[mb_strtolower($row['query'])])) {
                $matched += (int) $row['impressions'];

                continue;
            }
            foreach ($phrases as $phrase) {
                if (SeoText::containsPhrase($row['query'], $phrase)) {
                    $matched += (int) $row['impressions'];

                    break;
                }
            }
        }

        return $matched / max(1, $totalImpressions) >= SeoTaskConfig::float('understanding.fit_min_share', 0.03);
    }

    /** @return array<string, mixed>|null */
    private function cached(SeoPlan $plan): ?array
    {
        $days = SeoTaskConfig::int('understanding.cache_days', 28);
        $previous = SeoPlan::query()
            ->where('digital_asset_id', $plan->digital_asset_id)
            ->where('id', '<', $plan->id)
            ->where('status', SeoPlan::STATUS_COMPLETED)
            ->latest('id')
            ->first();
        $understanding = data_get($previous?->input_summary, 'site_understanding');
        if (! is_array($understanding) || ! is_array($understanding['services'] ?? null) || $understanding['services'] === []) {
            return null;
        }
        $generatedAt = strtotime((string) ($understanding['generated_at'] ?? ''));
        if ($generatedAt === false || $generatedAt < now()->subDays($days)->getTimestamp()) {
            return null;
        }
        if (($understanding['source'] ?? null) === self::SOURCE_CACHE) {
            $understanding['source'] = $understanding['source_detail'] ?? self::SOURCE_RULES;
        }

        return $understanding;
    }

    /** @return array{0: array<string, mixed>|null, 1: int} */
    private function fromAi(SeoPlan $plan, array $input): array
    {
        try {
            $route = $this->routes->resolve(AiRouteKeys::SEO_TASKS_SITE_UNDERSTANDING);
        } catch (Throwable) {
            return [['services' => [], 'skipped_reason' => 'route_unavailable'], 0];
        }
        if ($route->isEmpty()) {
            return [['services' => [], 'skipped_reason' => 'no_eligible_provider'], 0];
        }

        $context = $this->context($input);
        if ($context['pages'] === [] && $context['queries'] === []) {
            return [['services' => [], 'skipped_reason' => 'no_site_data'], 0];
        }

        try {
            $this->runtime->prepare(array_keys($route->providerModels));
            $response = (new SeoSiteUnderstandingAgent)->prompt(
                "CONTEXT_JSON\n".json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                provider: $route->providerModels,
                timeout: SeoTaskConfig::int('llm.timeout', 120),
            );
            $structured = $response->toArray();
        } catch (Throwable $exception) {
            Log::warning('SEO site understanding AI call failed; using rule-based topics.', ['plan_id' => $plan->id, 'error' => $exception->getMessage()]);

            return [['services' => [], 'skipped_reason' => 'llm_error'], 1];
        }
        if (! is_array($structured)) {
            return [['services' => [], 'skipped_reason' => 'invalid_response'], 1];
        }

        return [$this->validate($structured, $input, $route->primaryProvider(), $route->primaryModel()), 1];
    }

    /** Keep only facts that exist in the input: known page URLs and supplied queries. */
    private function validate(array $structured, array $input, ?string $provider, ?string $model): array
    {
        $pages = $input['pages'] ?? [];
        $queryLookup = [];
        foreach ($input['gsc']['rows'] ?? [] as $row) {
            $queryLookup[mb_strtolower($row['query'])] = $row['query'];
        }
        $max = SeoTaskConfig::int('understanding.max_services', 8);
        $services = [];
        $seen = [];
        foreach (is_array($structured['services'] ?? null) ? $structured['services'] : [] as $item) {
            if (! is_array($item) || count($services) >= $max) {
                continue;
            }
            $name = trim((string) ($item['name'] ?? ''));
            $key = SeoText::fold($name);
            // An article title ("… nedir?", "… nasıl kurulur?") is a blog topic, not a service.
            if (mb_strlen($name) < 2 || mb_strlen($name) > 80 || isset($seen[$key]) || SeoText::looksLikeArticleTitle($name)) {
                continue;
            }
            $seen[$key] = true;
            $pageUrl = is_string($item['page_url'] ?? null) ? trim($item['page_url']) : null;
            if ($pageUrl !== null && ! isset($pages[SeoText::urlKey($pageUrl)])) {
                $pageUrl = null;
            }
            $queries = [];
            foreach (is_array($item['queries'] ?? null) ? $item['queries'] : [] as $query) {
                if (is_string($query) && isset($queryLookup[mb_strtolower(trim($query))])) {
                    $queries[] = $queryLookup[mb_strtolower(trim($query))];
                }
            }
            $aliases = array_values(array_filter(
                is_array($item['aliases'] ?? null) ? $item['aliases'] : [],
                static fn ($alias): bool => is_string($alias) && mb_strlen(trim($alias)) >= 2 && mb_strlen($alias) <= 80,
            ));
            $services[] = [
                'name' => $name,
                'aliases' => array_slice(array_map('trim', $aliases), 0, 5),
                'page_url' => $pageUrl,
                'queries' => array_values(array_unique($queries)),
                'is_core' => (bool) ($item['is_core'] ?? false),
            ];
        }

        $locations = array_values(array_filter(
            is_array($structured['locations'] ?? null) ? $structured['locations'] : [],
            static fn ($location): bool => is_string($location) && trim($location) !== '' && mb_strlen($location) <= 80,
        ));

        return [
            'source' => self::SOURCE_AI,
            'provider' => $provider,
            'model' => $model,
            'prompt_version' => SeoSiteUnderstandingAgent::PROMPT_VERSION,
            'brand_summary' => mb_substr(trim((string) ($structured['brand_summary'] ?? '')), 0, 600),
            'audience' => mb_substr(trim((string) ($structured['audience'] ?? '')), 0, 200),
            'locations' => array_slice($locations, 0, 10),
            'services' => $this->markPriority($services),
        ];
    }

    /** Deterministic fallback: the site's own commercial pages that earn the most search impressions. */
    public function fromRules(array $input): array
    {
        $pages = $input['pages'] ?? [];
        $homeKey = $input['site']['home_key'] ?? '';
        $excluded = SeoTaskConfig::list('understanding.excluded_path_patterns');

        $bestPage = [];
        foreach ($input['gsc']['rows'] ?? [] as $row) {
            $key = mb_strtolower($row['query']);
            $current = $bestPage[$key] ?? null;
            $position = $row['position'] ?? 999.0;
            if ($current === null || $position < $current['position'] || ($position === $current['position'] && $row['impressions'] > $current['impressions'])) {
                $bestPage[$key] = ['query' => $row['query'], 'url_key' => $row['url_key'], 'position' => $position, 'impressions' => (int) $row['impressions']];
            }
        }
        $totals = [];
        $queries = [];
        foreach ($input['gsc']['rows'] ?? [] as $row) {
            $totals[$row['url_key']] = ($totals[$row['url_key']] ?? 0) + (int) $row['impressions'];
        }
        foreach ($bestPage as $entry) {
            $queries[$entry['url_key']][] = $entry;
        }

        $candidates = [];
        foreach ($pages as $key => $page) {
            if ($key === $homeKey || $page['noindex'] || ($page['status_code'] !== null && $page['status_code'] !== 200)) {
                continue;
            }
            $path = mb_strtolower($page['path']);
            foreach ($excluded as $pattern) {
                if (str_contains($path, (string) $pattern)) {
                    continue 2;
                }
            }
            $name = $this->topicName($page);
            if ($name === null || SeoText::looksLikeArticleTitle($name)) {
                continue; // article, not a service page
            }
            $candidates[] = [
                'key' => $key,
                'name' => $name,
                'impressions' => $totals[$key] ?? 0,
                'depth' => substr_count(trim($path, '/'), '/'),
            ];
        }
        usort($candidates, static fn (array $a, array $b): int => [$b['impressions'], $a['depth']] <=> [$a['impressions'], $b['depth']]);

        $services = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            if (count($services) >= SeoTaskConfig::int('understanding.fallback_services', 6)) {
                break;
            }
            $fold = SeoText::fold($candidate['name']);
            if (isset($seen[$fold])) {
                continue;
            }
            $seen[$fold] = true;
            $pageQueries = $queries[$candidate['key']] ?? [];
            usort($pageQueries, static fn (array $a, array $b): int => $b['impressions'] <=> $a['impressions']);
            $services[] = [
                'name' => $candidate['name'],
                'aliases' => [],
                'page_url' => $pages[$candidate['key']]['url'],
                'queries' => array_slice(array_column($pageQueries, 'query'), 0, 40),
                'is_core' => false,
            ];
        }

        return [
            'source' => self::SOURCE_RULES,
            'brand_summary' => null,
            'audience' => null,
            'locations' => [],
            'services' => $this->markPriority($services),
        ];
    }

    /** @param list<array<string, mixed>> $services @return list<array<string, mixed>> */
    private function markPriority(array $services): array
    {
        $limit = SeoTaskConfig::int('understanding.priority_count', 3);
        if (! array_filter($services, static fn (array $s): bool => $s['is_core'])) {
            foreach ($services as $index => &$service) {
                $service['is_core'] = $index < $limit;
            }
            unset($service);
        }

        return array_values($services);
    }

    /** @return list<array<string, mixed>> offering-shaped rows for the rule engine */
    private function toOfferings(array $services, array $input): array
    {
        $rows = [];
        foreach (array_values($services) as $index => $service) {
            $rows[] = [
                'id' => 'inferred:'.(SeoText::slugify($service['name']) ?: $index),
                'catalog_item_id' => null,
                'name' => $service['name'],
                'names' => array_values(array_unique(array_merge([$service['name']], $service['aliases'] ?? []))),
                'keywords' => [],
                'is_priority' => (bool) $service['is_core'],
                'priority_rank' => $index + 1,
                'queries' => $service['queries'] ?? [],
                'inferred' => true,
                'page_url' => $service['page_url'] ?? null,
            ];
        }

        return $rows;
    }

    private function topicName(array $page): ?string
    {
        $h1 = trim((string) ($page['h1'] ?? ''));
        if ($h1 !== '' && mb_strlen($h1) <= 60) {
            return $h1;
        }
        $title = trim((string) ($page['title'] ?? ''));
        if ($title === '') {
            return null;
        }
        $first = trim((string) preg_split('/\s[|\-–—:]\s/u', $title)[0]);

        return $first !== '' && mb_strlen($first) <= 70 ? $first : null;
    }

    /** @return array<string, mixed> */
    private function context(array $input): array
    {
        $pages = $input['pages'] ?? [];
        $home = $pages[$input['site']['home_key'] ?? ''] ?? null;
        $impressions = [];
        foreach ($input['gsc']['rows'] ?? [] as $row) {
            $impressions[$row['url_key']] = ($impressions[$row['url_key']] ?? 0) + (int) $row['impressions'];
        }
        $pageRows = [];
        foreach ($pages as $key => $page) {
            if ($page['noindex'] || ($page['status_code'] !== null && $page['status_code'] !== 200)) {
                continue;
            }
            $pageRows[] = ['url' => $page['url'], 'title' => $page['title'], 'h1' => $page['h1'], 'words' => $page['word_count'], 'gsc_impressions' => $impressions[$key] ?? 0, 'excerpt' => ($page['text_excerpt'] ?? '') !== '' ? mb_substr($page['text_excerpt'], 0, 600) : null];
        }
        usort($pageRows, static fn (array $a, array $b): int => $b['gsc_impressions'] <=> $a['gsc_impressions']);

        $queryTotals = [];
        foreach ($input['gsc']['rows'] ?? [] as $row) {
            $key = mb_strtolower($row['query']);
            $queryTotals[$key] ??= ['query' => $row['query'], 'impressions' => 0, 'clicks' => 0, 'best_page' => null, 'best_position' => null];
            $queryTotals[$key]['impressions'] += (int) $row['impressions'];
            $queryTotals[$key]['clicks'] += (int) $row['clicks'];
            if ($row['position'] !== null && ($queryTotals[$key]['best_position'] === null || $row['position'] < $queryTotals[$key]['best_position'])) {
                $queryTotals[$key]['best_position'] = $row['position'];
                $queryTotals[$key]['best_page'] = $row['page'];
            }
        }
        usort($queryTotals, static fn (array $a, array $b): int => $b['impressions'] <=> $a['impressions']);

        $landing = $input['ga4']['landing'] ?? [];
        uasort($landing, static fn (array $a, array $b): int => $b['sessions'] <=> $a['sessions']);

        return [
            'brand_name' => $input['site']['brand_name'] ?? null,
            'domain' => $input['site']['domain'] ?? null,
            'languages' => $input['site']['languages'] ?? [],
            'service_areas' => $input['service_areas'] ?? [],
            'homepage' => $home === null ? null : ['url' => $home['url'], 'title' => $home['title'], 'meta_description' => $home['meta_description'], 'h1' => $home['h1'], 'excerpt' => $home['text_excerpt'] ?? null],
            'pages' => array_slice($pageRows, 0, 80),
            'queries' => array_map(static fn (array $q): array => ['query' => $q['query'], 'impressions' => $q['impressions'], 'clicks' => $q['clicks'], 'best_page' => $q['best_page']], array_slice($queryTotals, 0, 200)),
            'ga4_top_landing_pages' => array_slice(array_map(static fn (string $key, array $row): array => ['page' => $key, 'sessions' => $row['sessions'], 'key_events' => $row['key_events']], array_keys($landing), $landing), 0, 20),
        ];
    }
}
