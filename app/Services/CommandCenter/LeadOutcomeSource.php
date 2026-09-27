<?php

namespace App\Services\CommandCenter;

use App\Models\LeadOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Lead quality loop in the command center: client leads older than two days without an outcome, one item per
 * brand (never one per lead). Done in the brand's lead page; here the item can only wait.
 */
final class LeadOutcomeSource implements CommandCenterSource
{
    public const int WAIT_DAYS = 2;

    public function items(): Collection
    {
        if (! Schema::hasTable('lead_outcomes')) {
            return collect();
        }

        return LeadOutcome::query()->with('brand')
            ->where('status', LeadOutcome::STATUS_NEW)
            ->where('lead_received_at', '<', now()->subDays(self::WAIT_DAYS))
            ->selectRaw('brand_id, count(*) as waiting, min(lead_received_at) as oldest')
            ->groupBy('brand_id')->get()
            ->map(function (LeadOutcome $row): array {
                $oldest = CarbonImmutable::parse((string) $row->getAttribute('oldest'));
                $waiting = (int) $row->getAttribute('waiting');

                return CommandCenter::item('lead_outcome', (int) $row->brand_id, $waiting >= 10 || $oldest->lt(now()->subDays(14)) ? 'high' : 'medium',
                    sprintf('%d lead\'in sonucu girilmedi', $waiting), [
                        'detail' => 'Klinikten randevu / satış / geçersiz bilgisini alıp işaretleyin; nitelikli lead başı maliyet buna göre hesaplanır. En eskisi '.$oldest->timezone(config('app.timezone'))->format('d.m.Y').'.',
                        'brand_id' => (int) $row->brand_id,
                        'brand' => $row->brand?->name,
                        'channel' => 'Lead kalitesi',
                        'url' => route('operator.brand.leads', ['brand' => $row->brand_id, 'status' => 'new']),
                        'actions' => ['snooze'],
                        'age' => $oldest,
                    ]);
            })->values();
    }
}
