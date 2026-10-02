<?php

namespace App\Contracts\Ai;

use App\Models\DigitalAsset;
use App\Support\Agents\AgentProfileDefinition;
use App\Support\Ai\AgentExecutionPlan;
use App\Support\Ai\EvidencePack;

/**
 * Bounded read gateway for Agent execution context packing (Prompt 50).
 *
 * Typed methods only — MUST NOT accept table/model names.
 * Module ContextBuilders remain the primary redaction path; this gateway
 * formalizes EvidencePack assembly from already-redacted context.
 */
interface AgentContextGateway
{
    /**
     * @param  array<string, mixed>  $contextPayload  already-built redacted context from module ContextBuilder
     * @param  list<int>  $evidenceIds
     * @param  list<int>  $findingIds
     */
    public function buildEvidencePackFromContext(
        DigitalAsset $asset,
        AgentProfileDefinition $profile,
        AgentExecutionPlan $plan,
        array $contextPayload,
        array $evidenceIds,
        array $findingIds,
        string $routeKey,
        string $routeSignature,
        string $inputFingerprint,
    ): EvidencePack;
}
