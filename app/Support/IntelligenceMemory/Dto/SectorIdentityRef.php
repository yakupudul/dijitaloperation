<?php

namespace App\Support\IntelligenceMemory\Dto;

/**
 * Operator-confirmed sector/group identity reference.
 *
 * Classification: OPERATOR_CONFIRMED_CONTEXT (catalog code on Brand/Customer).
 * Not AI-inferred. Missing prerequisite for full Prompt 53 Sector entity may exist.
 */
final class SectorIdentityRef
{
    public function __construct(
        public readonly ?string $code,
        public readonly string $source,
        public readonly bool $operatorCatalog = true,
        public readonly bool $aiInferred = false,
    ) {
        if ($this->aiInferred) {
            throw new \InvalidArgumentException('AI-inferred sector identity is forbidden.');
        }
    }

    public function isPresent(): bool
    {
        return $this->code !== null && $this->code !== '';
    }

    /**
     * @return array{
     *     code: string|null,
     *     source: string,
     *     operator_catalog: bool,
     *     ai_inferred: false
     * }
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'source' => $this->source,
            'operator_catalog' => $this->operatorCatalog,
            'ai_inferred' => false,
        ];
    }
}
