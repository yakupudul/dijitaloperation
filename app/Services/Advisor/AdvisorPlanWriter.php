<?php

namespace App\Services\Advisor;

use App\Enums\AdvisorItemStatus;
use App\Models\AdvisorItem;
use App\Models\AdvisorPlan;
use Illuminate\Support\Facades\DB;

/**
 * Persists advisor items with a diff against the previous run of the same asset + channel.
 *
 * Same item_key → refreshed; new → inserted; open item not produced this run → resolved (the problem
 * went away). A done item still detected after the verification grace is reopened (Faz 7); a skipped one
 * only when its snooze date passed; an operator draft is kept.
 */
final class AdvisorPlanWriter
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{created: int, updated: int, resolved: int, kept_resolved: int, counts: array<string, int>}
     */
    public function write(AdvisorPlan $plan, array $items): array
    {
        return DB::transaction(function () use ($plan, $items): array {
            $existing = AdvisorItem::query()
                ->where('digital_asset_id', $plan->digital_asset_id)
                ->where('channel', $plan->channel)
                ->get()
                ->keyBy('item_key');

            $created = 0;
            $updated = 0;
            $keptResolved = 0;
            $counts = [];

            foreach ($items as $candidate) {
                $counts[$candidate['category']] = ($counts[$candidate['category']] ?? 0) + 1;
                $attributes = [
                    'customer_id' => $plan->customer_id,
                    'brand_id' => $plan->brand_id,
                    'category' => $candidate['category'],
                    'rule_id' => $candidate['rule_id'],
                    'severity' => $candidate['severity'],
                    'priority_score' => $candidate['priority_score'],
                    'impact_amount' => $candidate['impact_amount'],
                    'impact_label' => $candidate['impact_label'],
                    'currency' => $candidate['currency'],
                    'title' => $candidate['title'],
                    'reason' => $candidate['reason'],
                    'evidence' => $candidate['evidence'],
                    'checklist' => $candidate['checklist'],
                    'copy_text' => $candidate['copy_text'],
                    'last_seen_plan_id' => $plan->id,
                ];

                /** @var AdvisorItem|null $row */
                $row = $existing->get($candidate['item_key']);
                if ($row === null) {
                    AdvisorItem::query()->create($attributes + [
                        'channel' => $plan->channel,
                        'digital_asset_id' => $plan->digital_asset_id,
                        'item_key' => $candidate['item_key'],
                        'baseline' => $candidate['baseline'],
                        'status' => AdvisorItemStatus::Open->value,
                        'first_seen_plan_id' => $plan->id,
                    ]);
                    $created++;

                    continue;
                }
                // Faz 7: a snoozed item comes back when its date passes; a done item still detected after the
                // grace period is reopened ("geri geldi"); before that it is only flagged "hâlâ görülüyor".
                $snoozeOver = $row->status === AdvisorItemStatus::Skipped && $row->snoozed_until !== null && $row->snoozed_until->isPast();
                $recurred = $row->status === AdvisorItemStatus::Done && $row->resolved_at !== null
                    && $row->resolved_at->lt(now()->subDays((int) config('moxdop-advisor.brain.verify_grace_days', 7)));
                if ($row->status === AdvisorItemStatus::Done && ! $recurred) {
                    $row->forceFill(['verification' => 'still_detected', 'verified_at' => now(), 'last_seen_plan_id' => $plan->id])->save();
                    $keptResolved++;

                    continue;
                }
                if ($row->status === AdvisorItemStatus::Skipped && ! $snoozeOver) {
                    $keptResolved++;

                    continue;
                }
                if ($recurred) {
                    $row->forceFill(['verification' => 'recurred', 'verified_at' => now(), 'reopened_count' => (int) $row->reopened_count + 1]);
                }
                if ($snoozeOver) {
                    $row->forceFill(['snoozed_until' => null]);
                }
                $reopened = in_array($row->status, [AdvisorItemStatus::Resolved, AdvisorItemStatus::Done, AdvisorItemStatus::Skipped], true);
                $row->fill($attributes + [
                    'status' => AdvisorItemStatus::Open->value,
                    'resolved_at' => null,
                    'resolved_by' => null,
                    'baseline' => $reopened ? $candidate['baseline'] : $row->baseline,
                ])->save();
                $updated++;
            }

            // Done items this run no longer detects are verified.
            AdvisorItem::query()
                ->where('digital_asset_id', $plan->digital_asset_id)
                ->where('channel', $plan->channel)
                ->where('status', AdvisorItemStatus::Done->value)
                ->where('last_seen_plan_id', '!=', $plan->id)
                ->where(fn ($q) => $q->whereNull('verification')->orWhereIn('verification', ['still_detected', 'recurred']))
                ->update(['verification' => 'verified', 'verified_at' => now(), 'updated_at' => now()]);

            $resolved = AdvisorItem::query()
                ->where('digital_asset_id', $plan->digital_asset_id)
                ->where('channel', $plan->channel)
                ->where('status', AdvisorItemStatus::Open->value)
                ->where('last_seen_plan_id', '!=', $plan->id)
                ->update(['status' => AdvisorItemStatus::Resolved->value, 'resolved_at' => now(), 'updated_at' => now()]);

            return ['created' => $created, 'updated' => $updated, 'resolved' => $resolved, 'kept_resolved' => $keptResolved, 'counts' => $counts];
        });
    }
}
