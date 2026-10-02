<?php

namespace App\Services\Integrations\DataForSeo;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * W5: account-wide monthly DataForSEO cap. Per-brand and per-feature caps still apply; this one stops every paid
 * call once the month's recorded spend reaches config('moxdop-intel.global_monthly_usd'). 0 disables the cap.
 */
final class DataForSeoSpendGuard
{
    public const string KIND_BUDGET = 'budget';

    public function cap(): float
    {
        return (float) config('moxdop-intel.global_monthly_usd', 0);
    }

    public function spentThisMonth(): float
    {
        try {
            return (float) DB::table('dataforseo_monthly_spend')->where('month', now()->format('Y-m'))->value('cost_usd');
        } catch (Throwable) {
            return 0.0;
        }
    }

    public function assertCanSpend(): void
    {
        $cap = $this->cap();
        if ($cap <= 0 || $this->spentThisMonth() < $cap) {
            return;
        }
        $this->bump(0.0, blocked: true);

        throw new DataForSeoException(sprintf('DataForSEO aylık genel tavanı (%.2f USD) doldu; ay başında yeniden açılır ya da Ayarlar’dan tavanı yükseltin.', $cap), kind: self::KIND_BUDGET);
    }

    public function record(?float $cost): void
    {
        if ($cost === null || $cost <= 0) {
            return;
        }
        $this->bump($cost);
    }

    private function bump(float $cost, bool $blocked = false): void
    {
        try {
            $month = now()->format('Y-m');
            DB::table('dataforseo_monthly_spend')->insertOrIgnore(['month' => $month, 'cost_usd' => 0, 'calls' => 0, 'blocked' => 0, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('dataforseo_monthly_spend')->where('month', $month)->update([
                'cost_usd' => DB::raw('cost_usd + '.round($cost, 5)),
                'calls' => DB::raw('calls + '.($blocked ? 0 : 1)),
                'blocked' => DB::raw('blocked + '.($blocked ? 1 : 0)),
                'updated_at' => now(),
            ]);
        } catch (Throwable $error) {
            report($error);
        }
    }
}
