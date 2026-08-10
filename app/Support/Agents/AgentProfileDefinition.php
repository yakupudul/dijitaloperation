<?php

namespace App\Support\Agents;

/**
 * Code-defined built-in Agent Profile contract (V1 — no database table).
 *
 * @phpstan-type AgentProfileArray array{
 *     slug: string,
 *     version: string,
 *     name: string,
 *     module: string,
 *     purpose: string,
 *     status: string,
 *     ai_route_key: string,
 *     skill_slugs: list<string>,
 *     allowed_data_scope: list<string>,
 *     allowed_operations: list<string>,
 *     forbidden_operations: list<string>,
 *     output_contract: string,
 *     success_criteria: list<string>
 * }
 */
final class AgentProfileDefinition
{
    /**
     * @param  list<string>  $skillSlugs
     * @param  list<string>  $allowedDataScope
     * @param  list<string>  $allowedOperations
     * @param  list<string>  $forbiddenOperations
     * @param  list<string>  $successCriteria
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $version,
        public readonly string $name,
        public readonly string $module,
        public readonly string $purpose,
        public readonly string $status,
        public readonly string $aiRouteKey,
        public readonly array $skillSlugs,
        public readonly array $allowedDataScope,
        public readonly array $allowedOperations,
        public readonly array $forbiddenOperations,
        public readonly string $outputContract,
        public readonly array $successCriteria,
    ) {}

    public function signature(): string
    {
        return $this->slug.'@'.$this->version;
    }

    /**
     * @return AgentProfileArray
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'version' => $this->version,
            'name' => $this->name,
            'module' => $this->module,
            'purpose' => $this->purpose,
            'status' => $this->status,
            'ai_route_key' => $this->aiRouteKey,
            'skill_slugs' => array_values($this->skillSlugs),
            'allowed_data_scope' => array_values($this->allowedDataScope),
            'allowed_operations' => array_values($this->allowedOperations),
            'forbidden_operations' => array_values($this->forbiddenOperations),
            'output_contract' => $this->outputContract,
            'success_criteria' => array_values($this->successCriteria),
        ];
    }
}
