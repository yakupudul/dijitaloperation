<?php

namespace App\Services\SeoTasks;

use App\Enums\SeoTaskStatus;
use App\Models\SeoPlan;
use App\Models\SeoTask;
use App\Models\ServicePageAssignment;
use Illuminate\Support\Facades\DB;

/**
 * Step D — persist tasks and assignments with a diff against the previous open set.
 *
 * Same task_key → update + last_seen_plan; new → insert; open task not produced this run → stale.
 * Done / skipped tasks are never touched. Operator page assignments are never overwritten.
 */
final class SeoPlanWriter
{
    /**
     * @param  list<array<string, mixed>>  $tasks
     * @param  list<array<string, mixed>>  $assignments
     * @return array{created: int, updated: int, stale: int, kept_resolved: int, counts: array<string, int>}
     */
    public function write(SeoPlan $plan, array $tasks, array $assignments): array
    {
        return DB::transaction(function () use ($plan, $tasks, $assignments): array {
            $this->writeAssignments($plan, $assignments);

            $existing = SeoTask::query()
                ->where('digital_asset_id', $plan->digital_asset_id)
                ->get()
                ->keyBy('task_key');

            $created = 0;
            $updated = 0;
            $keptResolved = 0;
            $seen = [];
            $counts = [];

            foreach ($tasks as $candidate) {
                $key = $candidate['task_key'];
                $seen[$key] = true;
                $counts[$candidate['type']] = ($counts[$candidate['type']] ?? 0) + 1;
                $attributes = [
                    'customer_id' => $plan->customer_id,
                    'brand_id' => $plan->brand_id,
                    'digital_asset_id' => $plan->digital_asset_id,
                    'brand_offering_id' => $candidate['brand_offering_id'] ?? null,
                    'type' => $candidate['type'],
                    'rule_id' => $candidate['rule_id'],
                    'severity' => $candidate['severity'],
                    'priority_score' => $candidate['priority_score'],
                    'estimated_extra_clicks' => $candidate['estimated_extra_clicks'] ?? null,
                    'title' => $candidate['title'],
                    'reason' => $candidate['reason'],
                    'evidence' => $candidate['evidence'] ?? [],
                    'checklist' => $candidate['checklist'] ?? [],
                    'target_url' => $candidate['target_url'] ?? null,
                    'is_new_page' => (bool) ($candidate['is_new_page'] ?? false),
                    'content_brief' => $candidate['content_brief'] ?? null,
                    'llm_payload' => $candidate['llm_payload'] ?? null,
                    'last_seen_plan_id' => $plan->id,
                ];

                /** @var SeoTask|null $row */
                $row = $existing->get($key);
                if ($row === null) {
                    SeoTask::query()->create($attributes + [
                        'task_key' => $key,
                        'status' => SeoTaskStatus::Open->value,
                        'first_seen_plan_id' => $plan->id,
                    ]);
                    $created++;

                    continue;
                }

                // Faz 7: same verification / recurrence / snooze handling as the advisor.
                $snoozeOver = $row->status === SeoTaskStatus::Skipped && $row->snoozed_until !== null && $row->snoozed_until->isPast();
                $recurred = $row->status === SeoTaskStatus::Done && $row->resolved_at !== null
                    && $row->resolved_at->lt(now()->subDays((int) config('moxdop-advisor.brain.verify_grace_days', 7)));
                if ($row->status === SeoTaskStatus::Done && ! $recurred) {
                    $row->forceFill(['verification' => 'still_detected', 'verified_at' => now(), 'last_seen_plan_id' => $plan->id])->save();
                    $keptResolved++;

                    continue;
                }
                if ($row->status === SeoTaskStatus::Skipped && ! $snoozeOver) {
                    $keptResolved++;

                    continue;
                }
                if ($recurred) {
                    $row->forceFill(['verification' => 'recurred', 'verified_at' => now(), 'reopened_count' => (int) $row->reopened_count + 1]);
                }
                if ($snoozeOver) {
                    $row->forceFill(['snoozed_until' => null]);
                }

                // A rules-only run must not overwrite what an earlier AI run wrote (AI output is kept);
                // evidence, severity and score still refresh.
                $keepsAiOutput = ($row->content_brief['source'] ?? null) === 'llm'
                    && ($attributes['content_brief']['source'] ?? null) !== 'llm';
                if ($keepsAiOutput) {
                    unset($attributes['content_brief'], $attributes['llm_payload'], $attributes['title'], $attributes['reason'], $attributes['checklist'], $attributes['target_url']);
                } elseif ($attributes['content_brief'] === null && $row->content_brief !== null) {
                    unset($attributes['content_brief'], $attributes['llm_payload']);
                }
                $row->fill($attributes + ['status' => SeoTaskStatus::Open->value, 'resolved_at' => null, 'resolved_by' => null]);
                $row->save();
                $updated++;
            }

            SeoTask::query()
                ->where('digital_asset_id', $plan->digital_asset_id)
                ->where('status', SeoTaskStatus::Done->value)
                ->where('last_seen_plan_id', '!=', $plan->id)
                ->where(fn ($q) => $q->whereNull('verification')->orWhereIn('verification', ['still_detected', 'recurred']))
                ->update(['verification' => 'verified', 'verified_at' => now(), 'updated_at' => now()]);

            $stale = SeoTask::query()
                ->where('digital_asset_id', $plan->digital_asset_id)
                ->where('status', SeoTaskStatus::Open->value)
                ->where('last_seen_plan_id', '!=', $plan->id)
                ->update(['status' => SeoTaskStatus::Stale->value, 'updated_at' => now()]);

            return [
                'created' => $created,
                'updated' => $updated,
                'stale' => $stale,
                'kept_resolved' => $keptResolved,
                'counts' => $counts,
            ];
        });
    }

    /** @param list<array<string, mixed>> $assignments */
    private function writeAssignments(SeoPlan $plan, array $assignments): void
    {
        foreach ($assignments as $assignment) {
            $existing = ServicePageAssignment::query()
                ->where('digital_asset_id', $plan->digital_asset_id)
                ->where('brand_offering_id', $assignment['brand_offering_id'])
                ->first();

            if ($existing !== null && $existing->isOperatorDecision()) {
                continue;
            }

            $payload = [
                'brand_id' => $plan->brand_id,
                'digital_asset_id' => $plan->digital_asset_id,
                'brand_offering_id' => $assignment['brand_offering_id'],
                'page_url' => $assignment['page_url'],
                'status' => $assignment['status'],
                'decision_source' => ServicePageAssignment::SOURCE_AUTO,
                'score' => $assignment['score'],
                'candidates' => $assignment['candidates'],
                'decided_at' => now(),
            ];

            if ($existing === null) {
                ServicePageAssignment::query()->create($payload);
            } else {
                $existing->fill($payload)->save();
            }
        }
    }
}
