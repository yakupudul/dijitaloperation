<?php

namespace App\Services\SeoTasks;

use App\Ai\Agents\SeoTaskContentPlannerAgent;
use App\Models\SeoPlan;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Step C — optional single LLM call. Any failure (no provider, schema mismatch, exception) leaves the
 * rule-generated tasks untouched; the plan still completes. The LLM never changes scores or task keys.
 */
final class SeoPlanAiEnricher
{
    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $tasks
     * @return array{tasks: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function enrich(SeoPlan $plan, array $input, array $tasks): array
    {
        $summary = ['enabled' => (bool) config('moxdop-seo-tasks.llm.enabled', true), 'calls' => 0, 'applied' => 0, 'skipped_reason' => null, 'provider' => null, 'model' => null, 'prompt_version' => SeoTaskContentPlannerAgent::PROMPT_VERSION];

        if (! $summary['enabled'] || $tasks === []) {
            $summary['skipped_reason'] = $tasks === [] ? 'no_tasks' : 'disabled';

            return ['tasks' => $tasks, 'summary' => $summary];
        }

        try {
            $route = $this->routes->resolve(AiRouteKeys::SEO_TASKS_CONTENT_PLANNER);
        } catch (Throwable $exception) {
            $summary['skipped_reason'] = 'route_unavailable';

            return ['tasks' => $tasks, 'summary' => $summary];
        }
        if ($route->isEmpty()) {
            $summary['skipped_reason'] = 'no_eligible_provider';

            return ['tasks' => $tasks, 'summary' => $summary];
        }

        $candidates = $this->candidates($tasks);
        if ($candidates === []) {
            $summary['skipped_reason'] = 'no_candidates';

            return ['tasks' => $tasks, 'summary' => $summary];
        }

        $payload = [
            'brand' => $input['site']['brand_name'] ?? null,
            'site' => ['domain' => $input['site']['domain'] ?? null, 'origin' => $input['site']['origin'] ?? null, 'languages' => $input['site']['languages'] ?? []],
            'period' => $input['period'] ?? null,
            'brand_understanding' => isset($input['understanding']) && is_array($input['understanding']) ? [
                'summary' => $input['understanding']['brand_summary'] ?? null,
                'audience' => $input['understanding']['audience'] ?? null,
                'locations' => $input['understanding']['locations'] ?? [],
                'services_inferred_from_site' => true,
            ] : null,
            'services' => array_map(static fn (array $o): array => ['name' => $o['name'], 'is_priority' => $o['is_priority']], $input['offerings'] ?? []),
            'pages' => $this->pageSummaries($input['pages'] ?? []),
            'candidates' => $candidates,
        ];

        try {
            $this->runtime->prepare(array_keys($route->providerModels));
            $summary['calls'] = 1;
            $summary['provider'] = $route->primaryProvider();
            $summary['model'] = $route->primaryModel();
            $response = (new SeoTaskContentPlannerAgent)->prompt(
                "CONTEXT_JSON\n".json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                provider: $route->providerModels,
                timeout: SeoTaskConfig::int('llm.timeout', 120),
            );
            $structured = $response->toArray();
        } catch (Throwable $exception) {
            Log::warning('SEO plan LLM enrichment failed; keeping rule tasks.', ['plan_id' => $plan->id, 'error' => $exception->getMessage()]);
            $summary['skipped_reason'] = 'llm_error';
            $summary['error'] = mb_substr($exception->getMessage(), 0, 300);

            return ['tasks' => $tasks, 'summary' => $summary];
        }

        if (! is_array($structured) || ! is_array($structured['items'] ?? null)) {
            $summary['skipped_reason'] = 'invalid_response';

            return ['tasks' => $tasks, 'summary' => $summary];
        }

        $byId = [];
        foreach ($structured['items'] as $item) {
            if (is_array($item) && is_string($item['candidate_id'] ?? null)) {
                $byId[$item['candidate_id']] = $item;
            }
        }

        $applied = 0;
        foreach ($tasks as &$task) {
            $item = $byId[$task['task_key']] ?? null;
            if ($item === null) {
                continue;
            }
            $merged = $this->apply($task, $item, $input['site']['origin'] ?? '');
            if ($merged !== null) {
                $task = $merged;
                $applied++;
            }
        }
        unset($task);

        $summary['applied'] = $applied;
        $summary['returned'] = count($byId);

        return ['tasks' => $tasks, 'summary' => $summary];
    }

    /** @return list<array<string, mixed>> */
    private function candidates(array $tasks): array
    {
        $max = (int) config('moxdop-seo-tasks.llm.max_candidates', 12);
        $ordered = array_values(array_filter($tasks, static fn (array $t): bool => $t['type'] !== 'question'));
        usort($ordered, static function (array $a, array $b): int {
            // Content briefs first (the weekly deliverable), then by score.
            $weight = static fn (array $t): int => $t['type'] === 'create' ? 1 : 0;

            return [$weight($b), $b['priority_score']] <=> [$weight($a), $a['priority_score']];
        });

        return array_map(static fn (array $t): array => [
            'candidate_id' => $t['task_key'],
            'type' => $t['type'],
            'rule_id' => $t['rule_id'],
            'title' => $t['title'],
            'reason' => $t['reason'],
            'checklist' => $t['checklist'],
            'target_url' => $t['target_url'],
            'evidence' => $t['evidence'],
            'brief' => $t['content_brief'],
        ], array_slice($ordered, 0, $max));
    }

    /** @return list<array<string, mixed>> */
    private function pageSummaries(array $pages): array
    {
        $max = (int) config('moxdop-seo-tasks.llm.max_page_summaries', 60);
        $rows = [];
        foreach ($pages as $page) {
            if (! $page['observed'] && $page['title'] === null) {
                continue;
            }
            if ($page['noindex'] || ($page['status_code'] !== null && $page['status_code'] !== 200)) {
                continue;
            }
            $rows[] = ['url' => $page['url'], 'title' => $page['title'], 'h1' => $page['h1'], 'words' => $page['word_count']];
        }

        return array_slice($rows, 0, $max);
    }

    /** Validate and merge one LLM item into a rule task. Returns null when nothing usable. */
    private function apply(array $task, array $item, string $origin): ?array
    {
        $title = is_string($item['title'] ?? null) ? trim($item['title']) : '';
        $reason = is_string($item['reason'] ?? null) ? trim($item['reason']) : '';
        $checklist = is_array($item['checklist'] ?? null)
            ? array_values(array_filter(array_map(static fn ($s) => is_string($s) ? trim($s) : '', $item['checklist']), static fn (string $s): bool => $s !== ''))
            : [];

        $changed = false;
        if ($title !== '' && mb_strlen($title) <= 160) {
            $task['title'] = mb_substr($title, 0, 255);
            $changed = true;
        }
        if ($reason !== '') {
            $task['reason'] = $reason;
            $changed = true;
        }
        if (count($checklist) >= 2) {
            $task['checklist'] = array_slice($checklist, 0, 10);
            $changed = true;
        }

        if ($task['type'] === 'create') {
            $allowedQueries = array_map('mb_strtolower', array_column($task['evidence']['queries'] ?? [], 'query'));
            $brief = is_array($item['brief'] ?? null) ? $item['brief'] : null;
            $decision = $item['decision'] ?? 'keep';
            if ($brief !== null) {
                $queries = array_values(array_filter(
                    is_array($brief['queries'] ?? null) ? $brief['queries'] : [],
                    static fn ($q): bool => is_string($q) && in_array(mb_strtolower($q), $allowedQueries, true),
                ));
                $targetUrl = is_string($brief['target_url_suggestion'] ?? null) ? trim($brief['target_url_suggestion']) : '';
                $sameHost = $targetUrl !== '' && SeoText::origin($targetUrl) === $origin;
                $existing = is_array($task['content_brief']) ? $task['content_brief'] : [];
                $task['content_brief'] = array_merge($existing, array_filter([
                    'page_title' => is_string($brief['page_title'] ?? null) && trim($brief['page_title']) !== '' ? trim($brief['page_title']) : null,
                    'page_type' => in_array($brief['page_type'] ?? null, ['service', 'guide', 'faq', 'location'], true) ? $brief['page_type'] : null,
                    'h2_outline' => is_array($brief['h2_outline'] ?? null) && count($brief['h2_outline']) >= 3 ? array_values(array_filter($brief['h2_outline'], 'is_string')) : null,
                    'queries' => $queries !== [] ? $queries : null,
                    'target_words' => is_int($brief['target_words'] ?? null) && $brief['target_words'] >= 300 ? $brief['target_words'] : null,
                    'target_url' => $sameHost ? $targetUrl : null,
                    'internal_links' => is_array($brief['internal_links'] ?? null) ? array_values(array_filter($brief['internal_links'], static fn ($u): bool => is_string($u) && SeoText::origin($u) === $origin)) : null,
                    'decision' => in_array($decision, ['new_page', 'existing_page_section'], true) ? $decision : null,
                ], static fn ($v): bool => $v !== null));
                $task['content_brief']['source'] = 'llm';
                if ($sameHost) {
                    $task['target_url'] = $targetUrl;
                }
                if (in_array($decision, ['new_page', 'existing_page_section'], true)) {
                    $task['is_new_page'] = $decision === 'new_page';
                }
                $changed = true;
            }
        }

        if (! $changed) {
            return null;
        }
        $task['llm_payload'] = ['prompt_version' => SeoTaskContentPlannerAgent::PROMPT_VERSION, 'decision' => $item['decision'] ?? null];

        return $task;
    }
}
