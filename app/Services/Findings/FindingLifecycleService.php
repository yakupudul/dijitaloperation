<?php

namespace App\Services\Findings;

use App\Events\FindingEvaluationCompleted;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Support\Findings\RuleEvaluationResult;
use App\Support\Findings\RuleMatch;
use Illuminate\Support\Facades\DB;

/**
 * Generic Finding persistence lifecycle (ADR-034).
 * No provider/domain thresholds — modules supply RuleEvaluationResult.
 */
final class FindingLifecycleService
{
    public const string STATUS_OPEN = 'open';

    public const string STATUS_ACKNOWLEDGED = 'acknowledged';

    public const string STATUS_RESOLVED = 'resolved';

    /**
     * @return array{opened: int, updated: int, reopened: int, resolved: int, recommendations: int}
     */
    public function apply(RuleEvaluationResult $result): array
    {
        $stats = [
            'opened' => 0,
            'updated' => 0,
            'reopened' => 0,
            'resolved' => 0,
            'recommendations' => 0,
        ];

        return DB::transaction(function () use ($result, $stats): array {
            $matchedFingerprints = [];

            foreach ($result->matches as $match) {
                $outcome = $this->upsertMatch($result, $match);
                $matchedFingerprints[] = $match->fingerprint;
                $stats[$outcome]++;
                if ($this->upsertRecommendation($result, $match)) {
                    $stats['recommendations']++;
                }
            }

            if ($result->evaluationSuccessful && $result->evaluatedRuleIds !== []) {
                $stats['resolved'] += $this->resolveUnmatched(
                    $result,
                    $matchedFingerprints,
                );
            }

            // ShouldDispatchAfterCommit on the event ensures Outcome monitors read committed Finding state.
            event(FindingEvaluationCompleted::fromResult($result, $stats));

            return $stats;
        });
    }

    private function upsertMatch(RuleEvaluationResult $result, RuleMatch $match): string
    {
        $finding = Finding::query()->firstOrNew([
            'digital_asset_id' => $result->asset->id,
            'fingerprint' => $match->fingerprint,
        ]);

        $outcome = 'updated';

        if (! $finding->exists) {
            $finding->first_seen_at = $result->observedAt;
            $finding->source_module = $result->sourceModule;
            $finding->status = self::STATUS_OPEN;
            $finding->resolved_at = null;
            $outcome = 'opened';
        } else {
            if ($finding->status === self::STATUS_RESOLVED) {
                $finding->status = self::STATUS_OPEN;
                $finding->resolved_at = null;
                $outcome = 'reopened';
            } elseif ($finding->status === self::STATUS_ACKNOWLEDGED) {
                // Preserve acknowledgement while issue is still present.
                $outcome = 'updated';
            } else {
                $finding->status = self::STATUS_OPEN;
                $outcome = 'updated';
            }
        }

        $finding->fill([
            'source_module' => $result->sourceModule,
            'category' => $match->category,
            'severity' => $match->severity,
            'title' => $match->title,
            'summary' => $match->summary,
            'confidence' => $match->confidence,
            'last_seen_at' => $result->observedAt,
            'last_run_id' => $result->run->id,
        ]);
        $finding->save();

        return $outcome;
    }

    /**
     * @param  list<string>  $matchedFingerprints
     */
    private function resolveUnmatched(RuleEvaluationResult $result, array $matchedFingerprints): int
    {
        $resolved = 0;
        $matchedLookup = array_fill_keys($matchedFingerprints, true);

        $candidates = Finding::query()
            ->where('digital_asset_id', $result->asset->id)
            ->where('source_module', $result->sourceModule)
            ->whereIn('status', [self::STATUS_OPEN, self::STATUS_ACKNOWLEDGED])
            ->get();

        foreach ($candidates as $finding) {
            if (! $this->ownedByEvaluatedRules((string) $finding->fingerprint, $result->evaluatedRuleIds)) {
                continue;
            }

            if (isset($matchedLookup[$finding->fingerprint])) {
                continue;
            }

            $finding->forceFill([
                'status' => self::STATUS_RESOLVED,
                'resolved_at' => $result->observedAt,
                'last_run_id' => $result->run->id,
            ])->save();
            $resolved++;
        }

        return $resolved;
    }

    /**
     * @param  list<string>  $evaluatedRuleIds
     */
    private function ownedByEvaluatedRules(string $fingerprint, array $evaluatedRuleIds): bool
    {
        foreach ($evaluatedRuleIds as $ruleId) {
            if ($fingerprint === $ruleId || str_starts_with($fingerprint, $ruleId.':')) {
                return true;
            }
        }

        return false;
    }

    private function upsertRecommendation(RuleEvaluationResult $result, RuleMatch $match): bool
    {
        if ($match->recommendationAction === null || trim($match->recommendationAction) === '') {
            return false;
        }

        $finding = Finding::query()
            ->where('digital_asset_id', $result->asset->id)
            ->where('fingerprint', $match->fingerprint)
            ->first();

        if ($finding === null) {
            return false;
        }

        $recommendation = Recommendation::query()->firstOrNew([
            'finding_id' => $finding->id,
            'source_module' => $result->sourceModule,
        ]);

        $priority = match ($match->severity) {
            'critical' => 'critical',
            'high' => 'high',
            'low' => 'low',
            default => 'medium',
        };

        $title = $match->recommendationTitle ?: ('Fix: '.$match->title);

        if ($recommendation->exists && ! in_array($recommendation->status, ['open', 'accepted'], true)) {
            $recommendation->fill([
                'digital_asset_id' => $finding->digital_asset_id,
                'title' => $title,
                'action' => $match->recommendationAction,
                'rationale' => $match->summary,
                'priority' => $priority,
            ]);
            $recommendation->save();

            return false;
        }

        $wasNew = ! $recommendation->exists;
        $recommendation->fill([
            'digital_asset_id' => $finding->digital_asset_id,
            'source_module' => $result->sourceModule,
            'title' => $title,
            'action' => $match->recommendationAction,
            'rationale' => $match->summary,
            'priority' => $priority,
            'effort' => null,
            'status' => $recommendation->exists ? $recommendation->status : 'open',
        ]);
        $recommendation->save();

        return $wasNew;
    }
}
