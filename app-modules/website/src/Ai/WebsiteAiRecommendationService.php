<?php

namespace MoxDop\Website\Ai;

use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Run;
use App\Services\Integrations\OpenAi\OpenAiRuntimeConfig;
use App\Support\BrandIntelligence\BrandIntelligenceSnapshot;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use MoxDop\Website\Ai\Agents\WebsiteRecommendationAgent;
use Throwable;

/**
 * Website-owned grounded AI recommendation orchestration (manual trigger only).
 */
final class WebsiteAiRecommendationService
{
    public function __construct(
        private readonly WebsiteAiRecommendationContextBuilder $contextBuilder,
        private readonly WebsiteAiGroundingValidator $grounding,
        private readonly OpenAiRuntimeConfig $openAiRuntime,
    ) {}

    /**
     * @param  list<int>|null  $findingIds
     * @return array{
     *     run: Run,
     *     reused: bool,
     *     message: string,
     *     insight: ?Evidence,
     *     brand_snapshot: BrandIntelligenceSnapshot
     * }
     */
    public function analyze(DigitalAsset $asset, ?array $findingIds = null): array
    {
        if ($asset->type !== 'website') {
            throw new InvalidArgumentException('Website AI insights require a website Digital Asset.');
        }

        $built = $this->contextBuilder->build($asset, $findingIds);
        $findings = $built['findings'];

        if ($findings->isEmpty()) {
            throw new InvalidArgumentException('Website AI insights require at least one Finding to interpret.');
        }

        $model = $this->openAiRuntime->recommendationModel();
        $fingerprint = WebsiteAiInputFingerprint::make(
            WebsiteAiRecommendationConfig::PROMPT_VERSION,
            WebsiteAiRecommendationConfig::SCHEMA_VERSION,
            $model,
            $built['context'],
        );

        $existing = $this->findReusableInsight($asset, $fingerprint);
        if ($existing instanceof Evidence) {
            $run = $existing->run ?? Run::query()->find($existing->run_id);

            return [
                'run' => $run instanceof Run ? $run : Run::query()->findOrFail($existing->run_id),
                'reused' => true,
                'message' => 'AI analysis is already current. No new AI request was made.',
                'insight' => $existing,
                'brand_snapshot' => $built['brand_snapshot'],
            ];
        }

        $runtime = $this->openAiRuntime->prepare();
        if (! $runtime['configured']) {
            throw new InvalidArgumentException('OpenAI is not configured. Add an API key in Settings → Integrations → OpenAI.');
        }

        $brandHash = $this->brandContextHash($built['brand_snapshot']);
        $run = Run::query()->create([
            'digital_asset_id' => $asset->id,
            'core_connection_id' => null,
            'module_id' => WebsiteAiRecommendationConfig::MODULE_ID,
            'status' => 'running',
            'started_at' => now(),
            'finished_at' => null,
            'metadata' => [
                'trigger' => 'manual',
                'human_title' => WebsiteAiRecommendationConfig::RUN_TITLE,
                'provider' => 'openai',
                'model' => $model,
                'prompt_version' => WebsiteAiRecommendationConfig::PROMPT_VERSION,
                'schema_version' => WebsiteAiRecommendationConfig::SCHEMA_VERSION,
                'finding_ids' => $built['finding_ids'],
                'evidence_ids' => $built['evidence_ids'],
                'brand_context_hash' => $brandHash,
                'input_fingerprint' => $fingerprint,
                'openai_store' => false,
            ],
        ]);

        $observedAt = now();

        try {
            $response = (new WebsiteRecommendationAgent)->prompt(
                $this->renderPrompt($built['context']),
                model: $model,
            );

            /** @var array<string, mixed> $structured */
            $structured = is_array($response->toArray())
                ? $response->toArray()
                : (array) $response;

            $payload = $this->grounding->validate(
                $structured,
                $built['finding_ids'],
                $built['evidence_ids'],
            );

            $usage = $this->extractUsage($response);
            $payload['finding_ids'] = $built['finding_ids'];
            $payload['evidence_ids'] = $built['evidence_ids'];
            $payload['input_fingerprint'] = $fingerprint;
            $payload['brand_context_hash'] = $brandHash;
            $payload['brand_completeness'] = $built['brand_snapshot']->completeness;
            $payload['model'] = $model;
            $payload['provider'] = 'openai';
            $payload['usage'] = $usage;

            $insight = Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $asset->id,
                'source_module' => WebsiteAiRecommendationConfig::MODULE_ID,
                'type' => WebsiteAiRecommendationConfig::EVIDENCE_TYPE_AI_INSIGHT,
                'title' => WebsiteAiRecommendationConfig::RUN_TITLE,
                'payload' => $payload,
                'observed_at' => $observedAt,
            ]);

            $run->update([
                'status' => 'completed',
                'finished_at' => now(),
                'metadata' => array_merge($run->metadata ?? [], [
                    'usage' => $usage,
                    'reused' => false,
                ]),
            ]);

            return [
                'run' => $run->fresh(['evidence']) ?? $run,
                'reused' => false,
                'message' => 'AI guidance generated.',
                'insight' => $insight,
                'brand_snapshot' => $built['brand_snapshot'],
            ];
        } catch (Throwable $exception) {
            // Never log secrets or full prompts — class name + safe message only.
            Log::warning('website_ai_recommendation_failed', [
                'digital_asset_id' => $asset->id,
                'run_id' => $run->id,
                'error_class' => class_basename($exception),
            ]);

            Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $asset->id,
                'source_module' => WebsiteAiRecommendationConfig::MODULE_ID,
                'type' => WebsiteAiRecommendationConfig::EVIDENCE_TYPE_AI_INSIGHT,
                'title' => WebsiteAiRecommendationConfig::RUN_TITLE,
                'payload' => [
                    'ok' => false,
                    'derived' => true,
                    'generated_by_ai' => true,
                    'finding_ids' => $built['finding_ids'],
                    'evidence_ids' => $built['evidence_ids'],
                    'input_fingerprint' => $fingerprint,
                    'executive_summary' => null,
                    'summary' => null,
                    'finding_interpretations' => [],
                    'recommendation_drafts' => [],
                    'error_class' => class_basename($exception),
                    'status_or_error' => 'ai_insight_failed',
                    'prompt_version' => WebsiteAiRecommendationConfig::PROMPT_VERSION,
                    'schema_version' => WebsiteAiRecommendationConfig::SCHEMA_VERSION,
                ],
                'observed_at' => $observedAt,
            ]);

            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'metadata' => array_merge($run->metadata ?? [], [
                    'error_class' => class_basename($exception),
                    'reused' => false,
                ]),
            ]);

            return [
                'run' => $run->fresh(['evidence']) ?? $run,
                'reused' => false,
                'message' => 'AI guidance failed. Previous successful guidance was preserved.',
                'insight' => $this->latestSuccessfulInsight($asset),
                'brand_snapshot' => $built['brand_snapshot'],
            ];
        }
    }

    public function latestSuccessfulInsight(DigitalAsset $asset): ?Evidence
    {
        return Evidence::query()
            ->where('digital_asset_id', $asset->id)
            ->where('source_module', WebsiteAiRecommendationConfig::MODULE_ID)
            ->where('type', WebsiteAiRecommendationConfig::EVIDENCE_TYPE_AI_INSIGHT)
            ->where('payload->ok', true)
            ->orderByDesc('id')
            ->first();
    }

    public function latestFailedInsight(DigitalAsset $asset): ?Evidence
    {
        return Evidence::query()
            ->where('digital_asset_id', $asset->id)
            ->where('source_module', WebsiteAiRecommendationConfig::MODULE_ID)
            ->where('type', WebsiteAiRecommendationConfig::EVIDENCE_TYPE_AI_INSIGHT)
            ->where(function ($query): void {
                $query->where('payload->ok', false)
                    ->orWhereNull('payload->ok');
            })
            ->orderByDesc('id')
            ->first();
    }

    private function findReusableInsight(DigitalAsset $asset, string $fingerprint): ?Evidence
    {
        return Evidence::query()
            ->where('digital_asset_id', $asset->id)
            ->where('source_module', WebsiteAiRecommendationConfig::MODULE_ID)
            ->where('type', WebsiteAiRecommendationConfig::EVIDENCE_TYPE_AI_INSIGHT)
            ->where('payload->ok', true)
            ->where('payload->input_fingerprint', $fingerprint)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function renderPrompt(array $context): string
    {
        $json = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
Analyze the following Website AI Recommendation Context.

Return structured guidance with executive_summary, overall_priority, context_observations,
and finding_interpretations (each with evidence_ids, uncertainty, recommendation_draft, watch signals).

Set prompt_version to "website-ai-recommendation-v1".

Do not invent facts outside this JSON.
Do not include assignee or due_date fields.
Do not recommend actions that violate important_constraints.

CONTEXT_JSON:
{$json}
PROMPT;
    }

    private function brandContextHash(BrandIntelligenceSnapshot $snapshot): string
    {
        $json = json_encode($snapshot->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', is_string($json) ? $json : '');
    }

    /**
     * @return array{prompt_tokens: int|null, completion_tokens: int|null, total_tokens: int|null}
     */
    private function extractUsage(mixed $response): array
    {
        $usage = is_object($response) && isset($response->usage) ? $response->usage : null;
        if (! is_object($usage)) {
            return [
                'prompt_tokens' => null,
                'completion_tokens' => null,
                'total_tokens' => null,
            ];
        }

        $prompt = isset($usage->promptTokens) ? (int) $usage->promptTokens : null;
        $completion = isset($usage->completionTokens) ? (int) $usage->completionTokens : null;
        $total = ($prompt !== null && $completion !== null) ? $prompt + $completion : null;

        return [
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $total,
        ];
    }
}
