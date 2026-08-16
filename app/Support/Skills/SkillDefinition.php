<?php

namespace App\Support\Skills;

/**
 * Curated built-in Skill contract loaded from module Markdown (V1 — no database table).
 *
 * Prompt 49 normalizes the definition contract. Prompt 50 owns execution.
 *
 * @phpstan-type SkillArray array{
 *     name: string,
 *     slug: string,
 *     version: string,
 *     module: string,
 *     purpose: string,
 *     definition_status: string,
 *     when_to_use: string,
 *     do_not_use_when: string,
 *     required_context: list<string>,
 *     required_evidence: list<string>,
 *     optional_evidence: list<string>,
 *     required_evidence_requirements: list<array<string, mixed>>,
 *     optional_evidence_requirements: list<array<string, mixed>>,
 *     required_capabilities: list<string>,
 *     optional_capabilities: list<string>,
 *     methodology: string,
 *     methodology_steps: list<array<string, mixed>>,
 *     rules: string,
 *     allowed_conclusions: list<string>,
 *     forbidden_claims: list<string>,
 *     effective_forbidden_claims: list<string>,
 *     abstention_rules: list<string>,
 *     dependencies: list<string>,
 *     output_contract: string,
 *     downstream_domains: list<string>,
 *     success_signals: list<string>,
 *     failure_signals: list<string>,
 *     watch_metrics: list<string>,
 *     reference_sources: list<string>,
 *     research_provenance: list<string>,
 *     definition_fingerprint: string,
 *     body_markdown: string,
 *     relative_path: string
 * }
 */
final class SkillDefinition
{
    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_DRAFT = 'draft';

    public const string STATUS_EXPERIMENTAL = 'experimental';

    public const string STATUS_NEEDS_REVIEW = 'needs_review';

    public const string STATUS_DEPRECATED = 'deprecated';

    /**
     * @param  list<string>  $requiredContext
     * @param  list<string>  $requiredEvidence
     * @param  list<string>  $optionalEvidence
     * @param  list<SkillEvidenceRequirement>  $requiredEvidenceRequirements
     * @param  list<SkillEvidenceRequirement>  $optionalEvidenceRequirements
     * @param  list<string>  $requiredCapabilities
     * @param  list<string>  $optionalCapabilities
     * @param  list<array{key: string, type: string, purpose: string, inputs: list<string>, validation: string, abstain_when: string}>  $methodologySteps
     * @param  list<string>  $allowedConclusions
     * @param  list<string>  $forbiddenClaims
     * @param  list<string>  $abstentionRules
     * @param  list<string>  $dependencies
     * @param  list<string>  $downstreamDomains
     * @param  list<string>  $successSignals
     * @param  list<string>  $failureSignals
     * @param  list<string>  $watchMetrics
     * @param  list<string>  $referenceSources
     * @param  list<string>  $researchProvenance
     */
    public function __construct(
        public readonly string $name,
        public readonly string $slug,
        public readonly string $version,
        public readonly string $module,
        public readonly string $purpose,
        public readonly string $definitionStatus,
        public readonly string $whenToUse,
        public readonly string $doNotUseWhen,
        public readonly array $requiredContext,
        public readonly array $requiredEvidence,
        public readonly array $optionalEvidence,
        public readonly array $requiredEvidenceRequirements,
        public readonly array $optionalEvidenceRequirements,
        public readonly array $requiredCapabilities,
        public readonly array $optionalCapabilities,
        public readonly string $methodology,
        public readonly array $methodologySteps,
        public readonly string $rules,
        public readonly array $allowedConclusions,
        public readonly array $forbiddenClaims,
        public readonly array $abstentionRules,
        public readonly array $dependencies,
        public readonly string $outputContract,
        public readonly array $downstreamDomains,
        public readonly array $successSignals,
        public readonly array $failureSignals,
        public readonly array $watchMetrics,
        public readonly array $referenceSources,
        public readonly array $researchProvenance,
        public readonly string $bodyMarkdown,
        public readonly string $relativePath,
    ) {}

    public function signature(): string
    {
        return $this->module.'.'.$this->slug.'@'.$this->version;
    }

    public function stableKey(): string
    {
        return $this->module.'.'.$this->slug;
    }

    /**
     * @return list<string>
     */
    public function effectiveForbiddenClaims(): array
    {
        return SkillGlobalClaimPolicy::effectiveForbiddenClaims($this->forbiddenClaims);
    }

    public function definitionFingerprint(): string
    {
        return SkillDefinitionFingerprint::hash([
            'module' => $this->module,
            'slug' => $this->slug,
            'version' => $this->version,
            'purpose' => $this->purpose,
            'definition_status' => $this->definitionStatus,
            'when_to_use' => $this->whenToUse,
            'do_not_use_when' => $this->doNotUseWhen,
            'required_context' => $this->requiredContext,
            'required_evidence_requirements' => array_map(
                static fn (SkillEvidenceRequirement $r): array => $r->toArray(),
                $this->requiredEvidenceRequirements
            ),
            'optional_evidence_requirements' => array_map(
                static fn (SkillEvidenceRequirement $r): array => $r->toArray(),
                $this->optionalEvidenceRequirements
            ),
            'methodology' => $this->methodology,
            'methodology_steps' => $this->methodologySteps,
            'allowed_conclusions' => $this->allowedConclusions,
            'forbidden_claims' => $this->forbiddenClaims,
            'abstention_rules' => $this->abstentionRules,
            'success_signals' => $this->successSignals,
            'reference_sources' => $this->referenceSources,
            'research_provenance' => $this->researchProvenance,
            'downstream_domains' => $this->downstreamDomains,
            'output_contract' => $this->outputContract,
        ]);
    }

    public function isProductionReady(): bool
    {
        return $this->definitionStatus === self::STATUS_ACTIVE;
    }

    /**
     * Bounded methodology excerpt suitable for LLM context (not full raw dump).
     */
    public function methodologyForPrompt(int $maxChars = 2400): string
    {
        $parts = array_filter([
            '## Purpose',
            $this->purpose,
            '## When to use',
            $this->whenToUse,
            '## Do not use when',
            $this->doNotUseWhen,
            '## Methodology',
            $this->methodology,
            '## Rules',
            $this->rules,
            '## Allowed conclusions',
            $this->bulletList($this->allowedConclusions),
            '## Forbidden claims',
            $this->bulletList($this->effectiveForbiddenClaims()),
            '## Abstention',
            $this->bulletList($this->abstentionRules),
            '## Output contract',
            $this->outputContract,
            '## Success signals',
            $this->bulletList($this->successSignals),
            '## Failure signals',
            $this->bulletList($this->failureSignals),
            '## Watch metrics',
            $this->bulletList($this->watchMetrics),
        ], fn (mixed $part): bool => is_string($part) && trim($part) !== '');

        $text = implode("\n\n", $parts);

        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $maxChars - 1)).'…';
    }

    /**
     * @return SkillArray
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'version' => $this->version,
            'module' => $this->module,
            'purpose' => $this->purpose,
            'definition_status' => $this->definitionStatus,
            'when_to_use' => $this->whenToUse,
            'do_not_use_when' => $this->doNotUseWhen,
            'required_context' => array_values($this->requiredContext),
            'required_evidence' => array_values($this->requiredEvidence),
            'optional_evidence' => array_values($this->optionalEvidence),
            'required_evidence_requirements' => array_map(
                static fn (SkillEvidenceRequirement $r): array => $r->toArray(),
                $this->requiredEvidenceRequirements
            ),
            'optional_evidence_requirements' => array_map(
                static fn (SkillEvidenceRequirement $r): array => $r->toArray(),
                $this->optionalEvidenceRequirements
            ),
            'required_capabilities' => array_values($this->requiredCapabilities),
            'optional_capabilities' => array_values($this->optionalCapabilities),
            'methodology' => $this->methodology,
            'methodology_steps' => array_values($this->methodologySteps),
            'rules' => $this->rules,
            'allowed_conclusions' => array_values($this->allowedConclusions),
            'forbidden_claims' => array_values($this->forbiddenClaims),
            'effective_forbidden_claims' => $this->effectiveForbiddenClaims(),
            'abstention_rules' => array_values($this->abstentionRules),
            'dependencies' => array_values($this->dependencies),
            'output_contract' => $this->outputContract,
            'downstream_domains' => array_values($this->downstreamDomains),
            'success_signals' => array_values($this->successSignals),
            'failure_signals' => array_values($this->failureSignals),
            'watch_metrics' => array_values($this->watchMetrics),
            'reference_sources' => array_values($this->referenceSources),
            'research_provenance' => array_values($this->researchProvenance),
            'definition_fingerprint' => $this->definitionFingerprint(),
            'body_markdown' => $this->bodyMarkdown,
            'relative_path' => $this->relativePath,
        ];
    }

    /**
     * @param  list<string>  $items
     */
    private function bulletList(array $items): string
    {
        if ($items === []) {
            return '(none)';
        }

        return implode("\n", array_map(fn (string $item): string => '- '.$item, $items));
    }
}
