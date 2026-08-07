<?php

namespace App\Services;

use App\Ai\Agents\WebsiteFindingInsightAgent;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Run;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

/**
 * Grounded Website AI interpretation over Findings + related Evidence (no external writes).
 */
class WebsiteAiInsightService
{
    public const MODULE_ID = 'website-ai-insights';

    public const EVIDENCE_TYPE_AI_INSIGHT = 'ai_insight';

    /**
     * Interpret open (or selected) Website Findings and persist ai_insight Evidence on a Run.
     *
     * @param  list<int>|null  $findingIds
     */
    public function interpret(DigitalAsset $asset, ?array $findingIds = null): Run
    {
        if ($asset->type !== 'website') {
            throw new InvalidArgumentException('Website AI insights require a website Digital Asset.');
        }

        $findings = $this->resolveFindings($asset, $findingIds);

        if ($findings->isEmpty()) {
            throw new InvalidArgumentException('Website AI insights require at least one Finding to interpret.');
        }

        $evidenceByRun = $this->loadRelatedEvidence($asset, $findings);
        $promptContext = $this->buildPromptContext($asset, $findings, $evidenceByRun);
        $evidenceIds = $evidenceByRun
            ->flatten(1)
            ->pluck('id')
            ->unique()
            ->values()
            ->all();

        $run = Run::query()->create([
            'digital_asset_id' => $asset->id,
            'core_connection_id' => null,
            'module_id' => self::MODULE_ID,
            'status' => 'running',
            'started_at' => now(),
            'finished_at' => null,
            'metadata' => [
                'trigger' => 'programmatic',
                'finding_ids' => $findings->pluck('id')->values()->all(),
                'evidence_ids' => $evidenceIds,
            ],
        ]);

        $observedAt = now();

        try {
            $response = (new WebsiteFindingInsightAgent)->prompt(
                $this->renderPrompt($promptContext),
            );

            /** @var array<string, mixed> $structured */
            $structured = is_array($response->toArray())
                ? $response->toArray()
                : (array) $response;

            $payload = $this->normalizeInsightPayload(
                structured: $structured,
                findingIds: $findings->pluck('id')->values()->all(),
                evidenceIds: $evidenceIds,
            );

            Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $asset->id,
                'source_module' => self::MODULE_ID,
                'type' => self::EVIDENCE_TYPE_AI_INSIGHT,
                'title' => 'Website AI finding interpretation',
                'payload' => $payload,
                'observed_at' => $observedAt,
            ]);

            $run->update([
                'status' => ($payload['ok'] ?? false) === true ? 'completed' : 'failed',
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $asset->id,
                'source_module' => self::MODULE_ID,
                'type' => self::EVIDENCE_TYPE_AI_INSIGHT,
                'title' => 'Website AI finding interpretation',
                'payload' => [
                    'ok' => false,
                    'finding_ids' => $findings->pluck('id')->values()->all(),
                    'evidence_ids' => $evidenceIds,
                    'summary' => null,
                    'finding_interpretations' => [],
                    'recommendation_drafts' => [],
                    'error_class' => class_basename($exception),
                    'status_or_error' => 'ai_insight_failed',
                ],
                'observed_at' => $observedAt,
            ]);

            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'metadata' => array_merge($run->metadata ?? [], [
                    'error_class' => class_basename($exception),
                ]),
            ]);
        }

        return $run->fresh(['evidence']) ?? $run;
    }

    /**
     * @param  list<int>|null  $findingIds
     * @return Collection<int, Finding>
     */
    private function resolveFindings(DigitalAsset $asset, ?array $findingIds): Collection
    {
        $query = Finding::query()
            ->where('digital_asset_id', $asset->id)
            ->orderBy('id');

        if ($findingIds === null) {
            return $query
                ->whereIn('status', ['open', 'acknowledged'])
                ->get();
        }

        $ids = collect($findingIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $findings = $query->whereIn('id', $ids->all())->get();

        if ($findings->count() !== $ids->count()) {
            throw new InvalidArgumentException('Website AI insights finding_ids must belong to the given website Digital Asset.');
        }

        return $findings;
    }

    /**
     * @param  Collection<int, Finding>  $findings
     * @return Collection<int, Collection<int, Evidence>>
     */
    private function loadRelatedEvidence(DigitalAsset $asset, Collection $findings): Collection
    {
        $runIds = $findings
            ->pluck('last_run_id')
            ->filter()
            ->unique()
            ->values();

        if ($runIds->isEmpty()) {
            return collect();
        }

        return Evidence::query()
            ->where('digital_asset_id', $asset->id)
            ->whereIn('run_id', $runIds->all())
            ->orderBy('id')
            ->get()
            ->groupBy('run_id');
    }

    /**
     * @param  Collection<int, Finding>  $findings
     * @param  Collection<int, Collection<int, Evidence>>  $evidenceByRun
     * @return array{
     *     digital_asset: array{id: int, type: string, primary_url: ?string},
     *     findings: list<array<string, mixed>>,
     *     evidence: list<array<string, mixed>>
     * }
     */
    private function buildPromptContext(DigitalAsset $asset, Collection $findings, Collection $evidenceByRun): array
    {
        $findingsPayload = $findings->map(function (Finding $finding): array {
            return [
                'id' => $finding->id,
                'fingerprint' => $finding->fingerprint,
                'category' => $finding->category,
                'severity' => $finding->severity,
                'title' => $finding->title,
                'summary' => $finding->summary,
                'confidence' => $finding->confidence,
                'status' => $finding->status,
                'last_run_id' => $finding->last_run_id,
            ];
        })->values()->all();

        $evidencePayload = $evidenceByRun
            ->flatten(1)
            ->map(function (Evidence $evidence): array {
                return [
                    'id' => $evidence->id,
                    'run_id' => $evidence->run_id,
                    'type' => $evidence->type,
                    'title' => $evidence->title,
                    'payload' => $this->compactEvidencePayload($evidence->payload ?? []),
                    'observed_at' => optional($evidence->observed_at)?->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        return [
            'digital_asset' => [
                'id' => $asset->id,
                'type' => $asset->type,
                'primary_url' => $asset->primary_url,
            ],
            'findings' => $findingsPayload,
            'evidence' => $evidencePayload,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function compactEvidencePayload(array $payload): array
    {
        $compact = [];

        foreach ($payload as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if (in_array($key, ['body', 'head_html', 'raw', 'html', 'content'], true) && is_string($value)) {
                $compact[$key.'_excerpt'] = mb_substr($value, 0, 400);
                $compact[$key.'_truncated'] = mb_strlen($value) > 400;

                continue;
            }

            if (is_array($value)) {
                $compact[$key] = $this->compactEvidencePayload($value);

                continue;
            }

            $compact[$key] = $value;
        }

        return $compact;
    }

    /**
     * @param  array{
     *     digital_asset: array{id: int, type: string, primary_url: ?string},
     *     findings: list<array<string, mixed>>,
     *     evidence: list<array<string, mixed>>
     * }  $context
     */
    private function renderPrompt(array $context): string
    {
        $json = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
Interpret the following Website Findings using only the supplied Evidence.

Return structured insight with:
1) an overall summary
2) per-finding likely cause, business impact, and suggested priority
3) recommendation drafts operators can edit before creating internal Tasks

Do not invent facts outside this JSON context.
Do not include assignee or due_date fields.

CONTEXT_JSON:
{$json}
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $structured
     * @param  list<int>  $findingIds
     * @param  list<int>  $evidenceIds
     * @return array<string, mixed>
     */
    private function normalizeInsightPayload(array $structured, array $findingIds, array $evidenceIds): array
    {
        $allowedFindingIds = collect($findingIds)->map(fn (int $id): int => $id)->all();
        $priorities = ['critical', 'high', 'medium', 'low'];

        $summary = is_string($structured['summary'] ?? null)
            ? trim($structured['summary'])
            : '';

        $interpretations = collect($structured['finding_interpretations'] ?? [])
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(function (array $row) use ($allowedFindingIds, $priorities): ?array {
                $findingId = (int) ($row['finding_id'] ?? 0);

                if (! in_array($findingId, $allowedFindingIds, true)) {
                    return null;
                }

                $priority = is_string($row['suggested_priority'] ?? null)
                    ? strtolower((string) $row['suggested_priority'])
                    : 'medium';

                if (! in_array($priority, $priorities, true)) {
                    $priority = 'medium';
                }

                return [
                    'finding_id' => $findingId,
                    'likely_cause' => trim((string) ($row['likely_cause'] ?? '')),
                    'business_impact' => trim((string) ($row['business_impact'] ?? '')),
                    'suggested_priority' => $priority,
                ];
            })
            ->filter()
            ->values()
            ->all();

        $drafts = collect($structured['recommendation_drafts'] ?? [])
            ->filter(fn (mixed $row): bool => is_array($row))
            ->map(function (array $row) use ($allowedFindingIds, $priorities): ?array {
                $findingId = (int) ($row['finding_id'] ?? 0);

                if (! in_array($findingId, $allowedFindingIds, true)) {
                    return null;
                }

                $priority = is_string($row['priority'] ?? null)
                    ? strtolower((string) $row['priority'])
                    : 'medium';

                if (! in_array($priority, $priorities, true)) {
                    $priority = 'medium';
                }

                $title = trim((string) ($row['title'] ?? ''));
                $action = trim((string) ($row['action'] ?? ''));
                $rationale = trim((string) ($row['rationale'] ?? ''));

                if ($title === '' || $action === '') {
                    return null;
                }

                return [
                    'finding_id' => $findingId,
                    'title' => $title,
                    'action' => $action,
                    'rationale' => $rationale,
                    'priority' => $priority,
                ];
            })
            ->filter()
            ->values()
            ->all();

        $ok = $summary !== '' && $interpretations !== [];

        return [
            'ok' => $ok,
            'finding_ids' => array_values($allowedFindingIds),
            'evidence_ids' => array_values($evidenceIds),
            'summary' => $summary !== '' ? $summary : null,
            'finding_interpretations' => $interpretations,
            'recommendation_drafts' => $drafts,
            'status_or_error' => $ok ? null : 'ai_insight_incomplete',
        ];
    }
}
