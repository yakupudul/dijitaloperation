<?php

namespace App\Support\Assistant\Dto;

/**
 * Pins exact sources used for an Assistant answer (Prompt 56).
 * Sector refs never include contributor identities.
 */
final class AssistantAnswerSourceManifest
{
    /**
     * @param  list<AssistantSourceRef>  $sourceRefs
     * @param  array<string, mixed>  $pins
     */
    public function __construct(
        public readonly array $sourceRefs,
        public readonly array $pins = [],
        public readonly ?string $retrievalManifestFingerprint = null,
        public readonly ?string $agentSkillRunRef = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_refs' => array_map(
                static fn (AssistantSourceRef $r) => $r->toArray(),
                $this->sourceRefs
            ),
            'pins' => $this->pins,
            'retrieval_manifest_fingerprint' => $this->retrievalManifestFingerprint,
            'agent_skill_run_ref' => $this->agentSkillRunRef,
            'sector_contributor_identities' => null,
        ];
    }
}
