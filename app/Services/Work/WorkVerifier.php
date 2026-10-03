<?php

namespace App\Services\Work;

use App\Models\DigitalAsset;
use App\Models\Suggestion;
use App\Services\Outcomes\OutcomeTracker;
use Illuminate\Support\Facades\DB;

/**
 * Genel işler, both ways of closing a system-check item (yakup, 2026-10-03: "Her ikisi"):
 *  - the operator says "Yaptım" → applied, verification `pending`; the next run of the same check confirms it
 *    (`confirmed`) or still finds the problem (`still_seen`, only on data pulled at least 12 hours after the click);
 *  - nobody clicked but the check now passes → applied by the system, verification `auto`.
 * Only explicit passes close items: a check without data (`nodata`) changes nothing.
 */
final class WorkVerifier
{
    /** Rule-based suggestion types and the action field that names their check. */
    public const array CHECK_TYPES = ['ads_check' => 'check', 'meta_check' => 'check', 'gbp_standard' => 'standard_id'];

    /** Data pulled sooner than this after "Yaptım" may predate the change, so it never marks it still seen. */
    public const int SETTLE_HOURS = 12;

    public function __construct(private readonly OutcomeTracker $outcomes) {}

    /**
     * @param  list<string>  $passed  check ids that passed in this run
     * @param  list<string>  $failing  check ids that still fail in this run
     * @return array{auto: int, confirmed: int, still_seen: int}
     */
    public function checks(DigitalAsset $asset, string $channel, string $actionType, array $passed, array $failing): array
    {
        $counts = ['auto' => 0, 'confirmed' => 0, 'still_seen' => 0];
        $field = self::CHECK_TYPES[$actionType] ?? null;
        if ($asset->brand_id === null || $field === null || ($passed === [] && $failing === [])) {
            return $counts;
        }
        $now = now();
        $rows = Suggestion::query()->where('brand_id', $asset->brand_id)->where('channel', $channel)
            ->where('target_id', $asset->id)->where('action_type', $actionType)
            ->whereIn('status', [Suggestion::OPEN, Suggestion::RECHECK, Suggestion::SNOOZED, Suggestion::APPROVED, Suggestion::APPLIED])->get();
        DB::transaction(function () use ($rows, $field, $passed, $failing, $now, &$counts): void {
            foreach ($rows as $row) {
                $check = (string) (((array) $row->action)[$field] ?? '');
                if ($check === '') {
                    continue;
                }
                if (in_array($check, $passed, true)) {
                    if ($row->status !== Suggestion::APPLIED) {
                        $this->outcomes->apply($row, null, ['facts' => $row->evidence ?? [], 'detected' => 'auto']);
                        $row->forceFill(['verification' => Suggestion::VERIFY_AUTO, 'verified_at' => $now])->save();
                        $counts['auto']++;
                    } elseif (in_array($row->verification, [Suggestion::VERIFY_PENDING, Suggestion::VERIFY_STILL_SEEN], true)) {
                        $row->forceFill(['verification' => Suggestion::VERIFY_CONFIRMED, 'verified_at' => $now])->save();
                        $counts['confirmed']++;
                    }

                    continue;
                }
                if (! in_array($check, $failing, true) || $row->verification !== Suggestion::VERIFY_PENDING) {
                    continue;
                }
                // Reopened by a materially different finding, or still applied and the data is newer than the click.
                $settled = $row->applied_at === null || $row->applied_at->lte($now->copy()->subHours(self::SETTLE_HOURS));
                if ($row->status === Suggestion::OPEN || ($row->status === Suggestion::APPLIED && $settled)) {
                    $row->forceFill(['verification' => Suggestion::VERIFY_STILL_SEEN, 'verified_at' => $now])->save();
                    $counts['still_seen']++;
                }
            }
        });

        return $counts;
    }
}
