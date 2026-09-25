<?php

namespace App\Services\Brain\Methods;

use Illuminate\Support\Facades\DB;

/**
 * Judges each method by the measured outcomes of the recommendations that applied it (latest of 56 / 28 days).
 * With at least `min_treated` measured cases: VALIDATED when the average effect against controls is positive and at
 * least `min_positive_share` of the cases beat their controls; RETIRED when the average effect is not positive.
 * Retired methods are no longer recommended; validated ones are shown first.
 */
final class MethodValidator
{
    /** @return int methods whose status changed */
    public function run(): int
    {
        $cfg = (array) config('moxdop-brain.validation');
        $changed = 0;
        $recs = DB::table('brain_recommendations')->whereNotNull('method_id')->where('status', 'done')->whereNotNull('outcome')->get(['method_id', 'outcome'])->groupBy('method_id');
        foreach ($recs as $methodId => $rows) {
            $effects = [];
            foreach ($rows as $row) {
                $outcome = (array) json_decode((string) $row->outcome, true);
                $last = ($outcome['d56']['status'] ?? null) === 'measured' ? $outcome['d56'] : (($outcome['d28']['status'] ?? null) === 'measured' ? $outcome['d28'] : null);
                if ($last !== null) {
                    $effects[] = (float) $last['effect'];
                }
            }
            $method = DB::table('brain_methods')->find($methodId);
            if ($method === null) {
                continue;
            }
            $summary = ['treated' => count($effects), 'mean_effect' => $effects === [] ? null : round(array_sum($effects) / count($effects), 4),
                'positive_share' => $effects === [] ? null : round(count(array_filter($effects, fn (float $e): bool => $e > 0)) / count($effects), 3)];
            $status = $method->status;
            if (count($effects) >= (int) $cfg['min_treated']) {
                $status = $summary['mean_effect'] > 0 && $summary['positive_share'] >= (float) $cfg['min_positive_share'] ? 'validated' : ($summary['mean_effect'] <= 0 ? 'retired' : $method->status);
            }
            DB::table('brain_methods')->where('id', $methodId)->update([
                'outcome' => json_encode($summary), 'status' => $status, 'updated_at' => now(),
                'validated_at' => $status === 'validated' && $method->status !== 'validated' ? now() : $method->validated_at,
            ]);
            $changed += $status !== $method->status ? 1 : 0;
        }

        return $changed;
    }
}
