<?php

namespace App\Services\Ai;

use App\Support\Ai\EvidencePack;
use InvalidArgumentException;

/**
 * Validates structured AI output arrays against an EvidencePack (Prompt 50).
 *
 * Does NOT write Findings, Opportunities, Recommendations, or Tasks.
 */
final class StructuredAgentOutputValidator
{
    /**
     * Forbidden scratch / CoT keys (case-insensitive, recursive).
     *
     * @var list<string>
     */
    private const FORBIDDEN_REASONING_KEYS = [
        'chain_of_thought',
        'internal_reasoning',
        'scratchpad',
    ];

    /**
     * Magic confidence numeric fields that must not appear.
     *
     * @var list<string>
     */
    private const FORBIDDEN_SCORE_KEYS = [
        'confidence_score',
        'ai_score',
        'seo_score',
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>|null  $allowedConclusions
     * @return array<string, mixed>
     */
    public function validate(
        array $payload,
        EvidencePack $pack,
        ?array $allowedConclusions = null,
    ): array {
        $this->assertNoForbiddenKeys($payload);
        $this->assertEvidenceIdsSubset($payload, $pack);

        if ($allowedConclusions !== null) {
            $this->assertConclusionsAllowed($payload, $allowedConclusions);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertEvidenceIdsSubset(array $payload, EvidencePack $pack): void
    {
        $allowed = array_fill_keys($pack->evidenceIds(), true);
        $referenced = $this->collectEvidenceIds($payload);

        foreach ($referenced as $id) {
            if (! isset($allowed[$id])) {
                throw new InvalidArgumentException(
                    "Structured AI output references evidence_id [{$id}] outside the Evidence Pack."
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<int>
     */
    private function collectEvidenceIds(array $payload): array
    {
        $ids = [];

        if (isset($payload['evidence_ids']) && is_array($payload['evidence_ids'])) {
            foreach ($payload['evidence_ids'] as $id) {
                if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                    $ids[] = (int) $id;
                }
            }
        }

        $this->walkForEvidenceRefs($payload, $ids);

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $node
     * @param  list<int>  $ids
     */
    private function walkForEvidenceRefs(array $node, array &$ids): void
    {
        foreach ($node as $key => $value) {
            if (is_string($key) && in_array($key, ['evidence_id', 'evidence_ref'], true)) {
                if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                    $ids[] = (int) $value;
                }
            }

            if (is_string($key) && $key === 'evidence_ids' && is_array($value)) {
                foreach ($value as $id) {
                    if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                        $ids[] = (int) $id;
                    }
                }
            }

            if (is_array($value)) {
                $this->walkForEvidenceRefs($value, $ids);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $allowedConclusions
     */
    private function assertConclusionsAllowed(array $payload, array $allowed): void
    {
        $allowedMap = array_fill_keys($allowed, true);
        $conclusions = [];

        if (isset($payload['conclusions']) && is_array($payload['conclusions'])) {
            foreach ($payload['conclusions'] as $item) {
                if (is_string($item)) {
                    $conclusions[] = $item;
                } elseif (is_array($item) && isset($item['type']) && is_string($item['type'])) {
                    $conclusions[] = $item['type'];
                }
            }
        }

        if (isset($payload['conclusion_types']) && is_array($payload['conclusion_types'])) {
            foreach ($payload['conclusion_types'] as $item) {
                if (is_string($item)) {
                    $conclusions[] = $item;
                }
            }
        }

        foreach ($conclusions as $type) {
            if (! isset($allowedMap[$type])) {
                throw new InvalidArgumentException(
                    "Structured AI output uses unknown conclusion type [{$type}]."
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $node
     */
    private function assertNoForbiddenKeys(array $node, string $path = ''): void
    {
        foreach ($node as $key => $value) {
            if (! is_string($key)) {
                if (is_array($value)) {
                    $this->assertNoForbiddenKeys($value, $path);
                }

                continue;
            }

            $lower = strtolower($key);
            $current = $path === '' ? $key : $path.'.'.$key;

            if (in_array($lower, self::FORBIDDEN_REASONING_KEYS, true)) {
                throw new InvalidArgumentException(
                    "Structured AI output must not include reasoning key [{$key}]."
                );
            }

            if (in_array($lower, self::FORBIDDEN_SCORE_KEYS, true)) {
                throw new InvalidArgumentException(
                    "Structured AI output must not include magic score field [{$key}]."
                );
            }

            if (is_array($value)) {
                $this->assertNoForbiddenKeys($value, $current);
            }
        }
    }
}
