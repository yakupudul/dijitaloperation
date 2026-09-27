<?php

namespace App\Services\LeadOutcomes;

use App\Models\Brand;
use App\Models\LeadOutcome;
use App\Services\Measurement\BrandMeasurementScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lead kalitesi for a brand and period: leads by outcome, qualified rate (appointment + sale / marked) and the
 * cost per qualified lead against the brand's Google Ads + Meta spend over the same days. Stored data only.
 */
final class LeadQuality
{
    /** Paid channel tables and their spend column; central rows (no asset id) win like in the monthly report. */
    private const array SPEND_TABLES = ['google_ads_campaign_daily' => 'cost_amount', 'meta_campaign_daily' => 'spend'];

    /**
     * @return array{from: string, to: string, total: int, marked: int, unmarked: int, by_status: array<string, int>, qualified: int, qualified_rate: ?float, value: float, spend: ?float, cost_per_lead: ?float, cost_per_qualified: ?float, by_source: array<string, int>}
     */
    public function forBrand(Brand $brand, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = LeadOutcome::query()->where('brand_id', $brand->id)
            ->whereBetween('lead_received_at', [$from->startOfDay(), $to->endOfDay()])
            ->selectRaw('status, lead_source, count(*) as leads, sum(value_try) as value')
            ->groupBy('status', 'lead_source')->get();
        $byStatus = array_fill_keys(array_keys(LeadOutcome::STATUSES), 0);
        $bySource = [];
        $value = 0.0;
        foreach ($rows as $row) {
            $byStatus[(string) $row->status] = ($byStatus[(string) $row->status] ?? 0) + (int) $row->leads;
            $bySource[(string) $row->lead_source] = ($bySource[(string) $row->lead_source] ?? 0) + (int) $row->leads;
            $value += (float) $row->value;
        }
        $total = array_sum($byStatus);
        $unmarked = $byStatus[LeadOutcome::STATUS_NEW];
        $marked = $total - $unmarked;
        $qualified = array_sum(array_intersect_key($byStatus, array_flip(LeadOutcome::QUALIFIED)));
        $spend = $this->spend($brand, $from, $to);

        return [
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'total' => $total, 'marked' => $marked, 'unmarked' => $unmarked,
            'by_status' => $byStatus, 'by_source' => $bySource,
            'qualified' => $qualified,
            'qualified_rate' => $marked > 0 ? round($qualified / $marked * 100, 1) : null,
            'value' => round($value, 2),
            'spend' => $spend,
            'cost_per_lead' => $spend !== null && $total > 0 ? round($spend / $total, 2) : null,
            'cost_per_qualified' => $spend !== null && $qualified > 0 ? round($spend / $qualified, 2) : null,
        ];
    }

    /** Google Ads + Meta spend of the brand in the period, or null when no paid channel has rows. */
    public function spend(Brand $brand, CarbonImmutable $from, CarbonImmutable $to): ?float
    {
        $scope = BrandMeasurementScope::for($brand);
        if ($scope->isEmpty()) {
            return null;
        }
        $total = null;
        foreach (self::SPEND_TABLES as $table => $column) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $query = $scope->apply(DB::table($table))->whereBetween('reporting_date', [$from->toDateString(), $to->toDateString()]);
            if (! (clone $query)->exists()) {
                continue;
            }
            if ((clone $query)->whereNull('digital_asset_id')->exists()) {
                $query->whereNull('digital_asset_id');
            }
            $total = ($total ?? 0.0) + (float) $query->sum($column);
        }

        return $total !== null ? round($total, 2) : null;
    }
}
