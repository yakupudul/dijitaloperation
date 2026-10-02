<?php

namespace App\Services\Ai;

use App\Models\AgentExecutionRun;
use App\Models\AiProviderAttempt;
use App\Models\DigitalAsset;
use App\Models\Run;
use App\Models\SkillExecutionRun;
use App\Support\Agents\AgentProfileDefinition;
use App\Support\Ai\AgentExecutionPlan;
use App\Support\Skills\SkillRegistry;
use InvalidArgumentException;

/**
 * Persists AgentExecutionRun / SkillExecutionRun / AiProviderAttempt provenance.
 *
 * Does not write Findings, Opportunities, Recommendations, or Tasks.
 * Eloquent persistence is allowed only in this recorder (and AgentContextGateway).
 */
final class AgentExecutionRecorder
{
    public function __construct(
        private readonly SkillRegistry $skills,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function startFromPlan(
        Run $run,
        DigitalAsset $asset,
        AgentProfileDefinition $profile,
        AgentExecutionPlan $plan,
        string $routeKey,
        string $routeSignature,
        string $inputFingerprint,
        ?int $requestedBy = null,
        array $metadata = [],
    ): AgentExecutionRun {
        $asset->loadMissing('brand');

        $status = match ($plan->preInferenceStatus) {
            AgentExecutionPlan::ABSTAINED_PRE_INFERENCE => AgentExecutionRun::STATUS_ABSTAINED,
            AgentExecutionPlan::BLOCKED_PRE_INFERENCE => AgentExecutionRun::STATUS_BLOCKED,
            default => AgentExecutionRun::STATUS_RUNNING,
        };

        $agentRun = AgentExecutionRun::query()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $asset->id,
            'customer_id' => $asset->brand?->customer_id,
            'brand_id' => $asset->brand_id,
            'agent_slug' => $profile->slug,
            'agent_version' => $profile->version,
            'ai_route_key' => $routeKey,
            'route_signature' => $routeSignature,
            'status' => $status,
            'input_fingerprint' => $inputFingerprint,
            'pre_inference_status' => $plan->preInferenceStatus,
            'block_reason_code' => $plan->blockReasonCode,
            'requested_by' => $requestedBy,
            'started_at' => now(),
            'completed_at' => null,
            'metadata' => array_merge([
                'plan' => $plan->toArray(),
                'eligible_skills' => $plan->eligibleSkills,
                'blocked_skills' => $plan->blockedSkills,
            ], $metadata),
        ]);

        foreach ($plan->skillEvaluations as $evaluation) {
            $signature = (string) ($evaluation['signature'] ?? '');
            if ($signature === '') {
                $slug = (string) ($evaluation['slug'] ?? '');
                $version = (string) ($evaluation['version'] ?? '');
                $module = (string) ($evaluation['module'] ?? $profile->module);
                $signature = $module.'.'.$slug.'@'.$version;
            }

            $eligible = (bool) ($evaluation['eligible'] ?? false);
            $skillStatus = $eligible
                ? SkillExecutionRun::STATUS_PENDING
                : SkillExecutionRun::STATUS_ABSTAINED;

            SkillExecutionRun::query()->create([
                'agent_execution_run_id' => $agentRun->id,
                'skill_module' => (string) ($evaluation['module'] ?? $profile->module),
                'skill_slug' => (string) ($evaluation['slug'] ?? ''),
                'skill_version' => (string) ($evaluation['version'] ?? ''),
                'skill_signature' => $signature,
                'status' => $skillStatus,
                'abstention_reason_code' => $eligible
                    ? null
                    : (isset($evaluation['reason_code']) ? (string) $evaluation['reason_code'] : null),
                'provider_attempt_count' => 0,
                'validated_output' => null,
                'eligibility' => $evaluation,
                'started_at' => $eligible ? now() : now(),
                'completed_at' => $eligible ? null : now(),
            ]);
        }

        return $agentRun->fresh(['skillExecutionRuns']) ?? $agentRun;
    }

    /**
     * @param  array<string, mixed>  $validatedOutput
     * @param  array<string, mixed>|null  $eligibility
     */
    public function markSkillValidated(
        AgentExecutionRun $agentRun,
        string $skillSignature,
        array $validatedOutput,
        ?array $eligibility = null,
    ): SkillExecutionRun {
        $skillRun = $this->findSkillRun($agentRun, $skillSignature);

        $skillRun->update([
            'status' => SkillExecutionRun::STATUS_VALIDATED,
            'abstention_reason_code' => null,
            'validated_output' => $validatedOutput,
            'eligibility' => $eligibility ?? $skillRun->eligibility,
            'completed_at' => now(),
        ]);

        return $skillRun->fresh() ?? $skillRun;
    }

    /**
     * @param  array<string, mixed>|null  $eligibility
     */
    public function markSkillAbstained(
        AgentExecutionRun $agentRun,
        string $skillSignature,
        string $reasonCode,
        ?array $eligibility = null,
    ): SkillExecutionRun {
        $skillRun = $this->findSkillRun($agentRun, $skillSignature);

        $skillRun->update([
            'status' => SkillExecutionRun::STATUS_ABSTAINED,
            'abstention_reason_code' => $reasonCode,
            'eligibility' => $eligibility ?? $skillRun->eligibility,
            'completed_at' => now(),
        ]);

        return $skillRun->fresh() ?? $skillRun;
    }

    /**
     * @param  array<string, mixed>  $metadataMerge
     */
    public function markCompleted(
        AgentExecutionRun $agentRun,
        string $status = AgentExecutionRun::STATUS_COMPLETED,
        array $metadataMerge = [],
    ): AgentExecutionRun {
        $agentRun->update([
            'status' => $status,
            'completed_at' => now(),
            'metadata' => array_merge($agentRun->metadata ?? [], $metadataMerge),
        ]);

        return $agentRun->fresh(['skillExecutionRuns']) ?? $agentRun;
    }

    /**
     * @param  array<string, mixed>|null  $usage
     */
    public function recordProviderAttempt(
        SkillExecutionRun $skillRun,
        int $attemptNumber,
        string $provider,
        string $model,
        string $status,
        ?string $providerRequestId = null,
        ?string $errorCategory = null,
        ?array $usage = null,
        ?int $latencyMs = null,
    ): AiProviderAttempt {
        $attempt = AiProviderAttempt::query()->create([
            'skill_execution_run_id' => $skillRun->id,
            'attempt_number' => $attemptNumber,
            'provider' => $provider,
            'model' => $model,
            'status' => $status,
            'provider_request_id' => $providerRequestId,
            'error_category' => $errorCategory,
            'usage' => $usage,
            'latency_ms' => $latencyMs,
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        $skillRun->update([
            'provider_attempt_count' => (int) $skillRun->provider_attempt_count + 1,
        ]);

        return $attempt;
    }

    private function findSkillRun(AgentExecutionRun $agentRun, string $skillSignature): SkillExecutionRun
    {
        $skillRun = SkillExecutionRun::query()
            ->where('agent_execution_run_id', $agentRun->id)
            ->where('skill_signature', $skillSignature)
            ->first();

        if (! $skillRun instanceof SkillExecutionRun) {
            // Lazily create when signature was not in the original plan evaluation list.
            try {
                $parts = explode('.', $skillSignature, 2);
                $module = $parts[0] ?? '';
                $rest = $parts[1] ?? $skillSignature;
                [$slug, $version] = array_pad(explode('@', $rest, 2), 2, '');
                $def = $this->skills->getForModule($module, $slug);

                $skillRun = SkillExecutionRun::query()->create([
                    'agent_execution_run_id' => $agentRun->id,
                    'skill_module' => $def->module,
                    'skill_slug' => $def->slug,
                    'skill_version' => $def->version !== '' ? $def->version : $version,
                    'skill_signature' => $skillSignature,
                    'status' => SkillExecutionRun::STATUS_PENDING,
                    'started_at' => now(),
                ]);
            } catch (InvalidArgumentException) {
                throw new InvalidArgumentException(
                    "Skill execution run not found for signature [{$skillSignature}]."
                );
            }
        }

        return $skillRun;
    }
}
