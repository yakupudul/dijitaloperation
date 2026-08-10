<?php

namespace App\Support\Skills;

/**
 * Curated built-in Skill contract loaded from module Markdown (V1 — no database table).
 *
 * @phpstan-type SkillArray array{
 *     name: string,
 *     slug: string,
 *     version: string,
 *     module: string,
 *     purpose: string,
 *     when_to_use: string,
 *     do_not_use_when: string,
 *     required_context: list<string>,
 *     required_evidence: list<string>,
 *     required_capabilities: list<string>,
 *     optional_capabilities: list<string>,
 *     methodology: string,
 *     rules: string,
 *     allowed_conclusions: list<string>,
 *     forbidden_claims: list<string>,
 *     dependencies: list<string>,
 *     output_contract: string,
 *     success_signals: list<string>,
 *     failure_signals: list<string>,
 *     watch_metrics: list<string>,
 *     reference_sources: list<string>,
 *     body_markdown: string,
 *     relative_path: string
 * }
 */
final class SkillDefinition
{
    /**
     * @param  list<string>  $requiredContext
     * @param  list<string>  $requiredEvidence
     * @param  list<string>  $requiredCapabilities
     * @param  list<string>  $optionalCapabilities
     * @param  list<string>  $allowedConclusions
     * @param  list<string>  $forbiddenClaims
     * @param  list<string>  $dependencies
     * @param  list<string>  $successSignals
     * @param  list<string>  $failureSignals
     * @param  list<string>  $watchMetrics
     * @param  list<string>  $referenceSources
     */
    public function __construct(
        public readonly string $name,
        public readonly string $slug,
        public readonly string $version,
        public readonly string $module,
        public readonly string $purpose,
        public readonly string $whenToUse,
        public readonly string $doNotUseWhen,
        public readonly array $requiredContext,
        public readonly array $requiredEvidence,
        public readonly array $requiredCapabilities,
        public readonly array $optionalCapabilities,
        public readonly string $methodology,
        public readonly string $rules,
        public readonly array $allowedConclusions,
        public readonly array $forbiddenClaims,
        public readonly array $dependencies,
        public readonly string $outputContract,
        public readonly array $successSignals,
        public readonly array $failureSignals,
        public readonly array $watchMetrics,
        public readonly array $referenceSources,
        public readonly string $bodyMarkdown,
        public readonly string $relativePath,
    ) {}

    public function signature(): string
    {
        return $this->slug.'@'.$this->version;
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
            $this->bulletList($this->forbiddenClaims),
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
            'when_to_use' => $this->whenToUse,
            'do_not_use_when' => $this->doNotUseWhen,
            'required_context' => array_values($this->requiredContext),
            'required_evidence' => array_values($this->requiredEvidence),
            'required_capabilities' => array_values($this->requiredCapabilities),
            'optional_capabilities' => array_values($this->optionalCapabilities),
            'methodology' => $this->methodology,
            'rules' => $this->rules,
            'allowed_conclusions' => array_values($this->allowedConclusions),
            'forbidden_claims' => array_values($this->forbiddenClaims),
            'dependencies' => array_values($this->dependencies),
            'output_contract' => $this->outputContract,
            'success_signals' => array_values($this->successSignals),
            'failure_signals' => array_values($this->failureSignals),
            'watch_metrics' => array_values($this->watchMetrics),
            'reference_sources' => array_values($this->referenceSources),
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
