<?php

namespace MoxDop\GoogleAds\Ai;

use InvalidArgumentException;

/**
 * Server-side grounding gate for structured AI recommendation output.
 */
final class GoogleAdsAiGroundingValidator
{
    /**
     * @param  array<string, mixed>  $structured
     * @param  list<int>  $allowedFindingIds
     * @param  list<int>  $allowedEvidenceIds
     * @return array<string, mixed>
     */
    public function validate(array $structured, array $allowedFindingIds, array $allowedEvidenceIds): array
    {
        $allowedFindings = array_values(array_unique(array_map('intval', $allowedFindingIds)));
        $allowedEvidence = array_values(array_unique(array_map('intval', $allowedEvidenceIds)));

        $executive = trim((string) ($structured['executive_summary'] ?? ''));
        if ($executive === '') {
            throw new InvalidArgumentException('AI structured output missing executive_summary.');
        }

        $priority = strtolower(trim((string) ($structured['overall_priority'] ?? 'medium')));
        if (! in_array($priority, ['critical', 'high', 'medium', 'low'], true)) {
            $priority = 'medium';
        }

        $observations = collect($structured['context_observations'] ?? [])
            ->filter(fn (mixed $row): bool => is_string($row) && trim($row) !== '')
            ->map(fn (string $row): string => trim($row))
            ->values()
            ->all();

        $interpretations = [];
        foreach ($structured['finding_interpretations'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $findingId = (int) ($row['finding_id'] ?? 0);
            if (! in_array($findingId, $allowedFindings, true)) {
                throw new InvalidArgumentException('AI output referenced unknown finding_id.');
            }

            $evidenceIds = collect($row['evidence_ids'] ?? [])
                ->map(fn (mixed $id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();

            foreach ($evidenceIds as $evidenceId) {
                if (! in_array($evidenceId, $allowedEvidence, true)) {
                    throw new InvalidArgumentException('AI output referenced unknown evidence_id.');
                }
            }

            $draft = is_array($row['recommendation_draft'] ?? null) ? $row['recommendation_draft'] : [];
            $title = trim((string) ($draft['title'] ?? ''));
            $action = trim((string) ($draft['action'] ?? ''));
            $rationale = trim((string) ($draft['rationale'] ?? ''));
            $effort = strtolower(trim((string) ($draft['effort'] ?? 'medium')));
            if (! in_array($effort, ['low', 'medium', 'high'], true)) {
                $effort = 'medium';
            }

            $suggested = strtolower(trim((string) ($row['suggested_priority'] ?? 'medium')));
            if (! in_array($suggested, ['critical', 'high', 'medium', 'low'], true)) {
                $suggested = 'medium';
            }

            $uncertainty = strtolower(trim((string) ($row['uncertainty'] ?? 'medium')));
            if (! in_array($uncertainty, ['low', 'medium', 'high'], true)) {
                $uncertainty = 'medium';
            }

            $interpretations[] = [
                'finding_id' => $findingId,
                'evidence_ids' => $evidenceIds,
                'explanation' => trim((string) ($row['explanation'] ?? '')),
                'business_relevance' => trim((string) ($row['business_relevance'] ?? '')),
                'likely_contributors' => $this->stringList($row['likely_contributors'] ?? []),
                'uncertainty' => $uncertainty,
                'suggested_priority' => $suggested,
                'recommendation_draft' => [
                    'title' => $title,
                    'action' => $action,
                    'rationale' => $rationale,
                    'effort' => $effort,
                    'priority' => $suggested,
                ],
                'dependencies' => $this->stringList($row['dependencies'] ?? []),
                'success_signal' => trim((string) ($row['success_signal'] ?? '')),
                'failure_signal' => trim((string) ($row['failure_signal'] ?? '')),
                'watch_metrics' => $this->stringList($row['watch_metrics'] ?? []),
            ];
        }

        if ($interpretations === []) {
            throw new InvalidArgumentException('AI structured output missing grounded finding_interpretations.');
        }

        // Compatibility drafts list for acceptance UX / legacy readers.
        $drafts = array_values(array_filter(array_map(function (array $interpretation): ?array {
            $draft = $interpretation['recommendation_draft'];
            if ($draft['title'] === '' || $draft['action'] === '') {
                return null;
            }

            return [
                'finding_id' => $interpretation['finding_id'],
                'title' => $draft['title'],
                'action' => $draft['action'],
                'rationale' => $draft['rationale'],
                'priority' => $draft['priority'],
                'effort' => $draft['effort'],
            ];
        }, $interpretations)));

        return [
            'ok' => true,
            'executive_summary' => $executive,
            'summary' => $executive,
            'overall_priority' => $priority,
            'context_observations' => $observations,
            'finding_interpretations' => $interpretations,
            'recommendation_drafts' => $drafts,
            'prompt_version' => GoogleAdsAiGuidanceConfig::PROMPT_VERSION,
            'schema_version' => GoogleAdsAiGuidanceConfig::SCHEMA_VERSION,
            'derived' => true,
            'generated_by_ai' => true,
            'status_or_error' => null,
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->filter(fn (mixed $row): bool => is_string($row) && trim($row) !== '')
            ->map(fn (string $row): string => trim($row))
            ->values()
            ->all();
    }
}
