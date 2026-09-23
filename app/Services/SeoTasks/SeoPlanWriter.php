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

                if (in_array($row->status, [SeoTaskStatus::Done, SeoTaskStatus::Skipped], true)) {
                    $keptResolved++;

                    continue;
                }

                $row->fill($attributes + ['status' => SeoTaskStatus::Open->value, 'resolved_at' => null, 'resolved_by' => null]);
                $row->save();
                $updated++;
            }

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
