<?php

namespace App\Support\Assistant\Dto;

use App\Enums\AssistantSourceClass;

/**
 * Opaque typed source reference — never copies full payloads.
 */
final class AssistantSourceRef
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly AssistantSourceClass $sourceClass,
        public readonly string $opaqueRef,
        public readonly ?string $fingerprint = null,
        public readonly array $metadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_class' => $this->sourceClass->value,
            'opaque_ref' => $this->opaqueRef,
            'fingerprint' => $this->fingerprint,
            'metadata' => $this->metadata,
        ];
    }
}
