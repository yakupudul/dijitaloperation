<?php

namespace MoxDop\Website\Ai;

use App\Enums\RecommendationOrigin;
use App\Enums\RecommendationSourceKind;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Recommendation;
use InvalidArgumentException;

/**
 * Human acceptance gate: AI draft → Recommendation row (never auto Task).
 */
final class WebsiteAiRecommendationAcceptance
{
    /**
     * @return array{recommendation: Recommendation, created: bool, updated: bool, message: string}
     */
    public function acceptDraft(DigitalAsset $asset, int $findingId, ?Evidence $insight = null): array
    {
        if ($asset->type !== 'website') {
            throw new InvalidArgumentException('AI recommendation acceptance requires a website Digital Asset.');
        }

        $insight ??= app(WebsiteAiRecommendationService::class)->latestSuccessfulInsight($asset);
        if (! $insight instanceof Evidence || ($insight->payload['ok'] ?? false) !== true) {
            throw new InvalidArgumentException('No successful AI guidance is available to accept.');
        }

        $finding = Finding::query()
            ->where('digital_asset_id', $asset->id)
            ->where('id', $findingId)
            ->first();

        if (! $finding instanceof Finding) {
            throw new InvalidArgumentException('Finding not found for this website.');
        }

        $draft = $this->draftForFinding($insight, $findingId);
        if ($draft === null) {
            throw new InvalidArgumentException('No AI recommendation draft exists for this Finding.');
        }

        $existingAi = Recommendation::query()
            ->where('digital_asset_id', $asset->id)
            ->where('finding_id', $findingId)
            ->where('source_module', WebsiteAiRecommendationConfig::MODULE_ID)
            ->orderByDesc('id')
            ->first();

        if ($existingAi instanceof Recommendation) {
            if (in_array($existingAi->status, ['dismissed', 'converted'], true)) {
                return [
                    'recommendation' => $existingAi,
                    'created' => false,
                    'updated' => false,
                    'message' => 'Existing AI-assisted recommendation is in a terminal state and was not overwritten.',
                ];
            }

            $existingAi->update([
                'title' => $draft['title'],
                'action' => $draft['action'],
                'rationale' => $draft['rationale'],
                'priority' => $draft['priority'],
                'effort' => $draft['effort'],
            ]);

            return [
                'recommendation' => $existingAi->fresh() ?? $existingAi,
                'created' => false,
                'updated' => true,
                'message' => 'AI-assisted recommendation updated.',
            ];
        }

        // Never overwrite another source_module's deterministic Recommendation.
        $recommendation = Recommendation::query()->create([
            'source_kind' => RecommendationSourceKind::Finding->value,
            'finding_id' => $finding->id,
            'opportunity_id' => null,
            'origin' => RecommendationOrigin::Operator->value,
            'digital_asset_id' => $asset->id,
            'source_module' => WebsiteAiRecommendationConfig::MODULE_ID,
            'title' => $draft['title'],
            'action' => $draft['action'],
            'rationale' => $draft['rationale'],
            'priority' => $draft['priority'],
            'effort' => $draft['effort'],
            'status' => 'open',
        ]);

        return [
            'recommendation' => $recommendation,
            'created' => true,
            'updated' => false,
            'message' => 'AI-assisted recommendation created.',
        ];
    }

    /**
     * @return array{title: string, action: string, rationale: string, priority: string, effort: string}|null
     */
    private function draftForFinding(Evidence $insight, int $findingId): ?array
    {
        $payload = is_array($insight->payload) ? $insight->payload : [];

        foreach ($payload['finding_interpretations'] ?? [] as $row) {
            if (! is_array($row) || (int) ($row['finding_id'] ?? 0) !== $findingId) {
                continue;
            }
            $draft = is_array($row['recommendation_draft'] ?? null) ? $row['recommendation_draft'] : null;
            if ($draft === null) {
                continue;
            }

            return $this->normalizeDraft($draft, $row);
        }

        foreach ($payload['recommendation_drafts'] ?? [] as $row) {
            if (! is_array($row) || (int) ($row['finding_id'] ?? 0) !== $findingId) {
                continue;
            }

            return $this->normalizeDraft($row, $row);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $fallback
     * @return array{title: string, action: string, rationale: string, priority: string, effort: string}|null
     */
    private function normalizeDraft(array $draft, array $fallback): ?array
    {
        $title = trim((string) ($draft['title'] ?? ''));
        $action = trim((string) ($draft['action'] ?? ''));
        if ($title === '' || $action === '') {
            return null;
        }

        $priority = strtolower(trim((string) ($draft['priority'] ?? $fallback['suggested_priority'] ?? 'medium')));
        if (! in_array($priority, ['critical', 'high', 'medium', 'low'], true)) {
            $priority = 'medium';
        }

        $effort = strtolower(trim((string) ($draft['effort'] ?? 'medium')));
        if (! in_array($effort, ['low', 'medium', 'high'], true)) {
            $effort = 'medium';
        }

        return [
            'title' => $title,
            'action' => $action,
            'rationale' => trim((string) ($draft['rationale'] ?? '')),
            'priority' => $priority,
            'effort' => $effort,
        ];
    }
}
