<?php

namespace App\Livewire\Operator\GoogleAds\Desk;

use App\Services\GoogleAds\Desk\GoogleAdsDesk;
use App\Services\GoogleAds\GoogleAdsChecks;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Google Ads › Durum (`/google-ads`): one row per Google Ads account of every operational brand with the last 30 days
 * (spend, conversions, cost per conversion and the change), the red strip (data stopped, delivery stopped, budget or
 * payment problems), the failing system checks and what waits on the other tabs. A row opens six months, the
 * costliest campaigns and what MoxDOP wrote to the account.
 */
#[Layout('operator.layouts.app')]
#[Title('Google Ads')]
final class DeskPage extends Component
{
    use DeskScope;

    /** Account whose details are open. */
    #[Url(as: 'hesap')]
    public ?int $open = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_active, 403);
    }

    public function toggle(int $assetId): void
    {
        $this->open = $this->open === $assetId ? null : $assetId;
    }

    public function render(GoogleAdsDesk $desk): View
    {
        $accounts = $this->scopedAccounts();
        $rows = $desk->status($accounts);
        $totals = ['cost' => 0.0, 'conversions' => 0.0, 'prev_cost' => 0.0, 'prev_conversions' => 0.0];
        foreach ($rows as $row) {
            $totals['cost'] += (float) ($row['current']['cost'] ?? 0);
            $totals['conversions'] += (float) ($row['current']['conversions'] ?? 0);
            $totals['prev_cost'] += (float) ($row['previous']['cost'] ?? 0);
            $totals['prev_conversions'] += (float) ($row['previous']['conversions'] ?? 0);
        }
        $currencies = array_values(array_unique(array_filter(array_column($rows, 'currency'))));
        $openRow = $this->open !== null ? collect($rows)->first(fn (array $r): bool => (int) $r['asset']->id === $this->open) : null;

        return view('livewire.operator.google-ads.desk.desk-page', [
            'rows' => $rows,
            'problems' => GoogleAdsDesk::problems($rows),
            'shared' => GoogleAdsDesk::sharedGaps($rows),
            'totals' => $totals,
            'currency' => count($currencies) === 1 ? $currencies[0] : '',
            'live' => count(array_filter($rows, fn (array $r): bool => in_array($r['state'], ['ok', 'late'], true))),
            'detail' => $openRow !== null ? $desk->detail($openRow['asset']) : null,
            'brandOptions' => $desk->brandOptions(),
            'checkLabels' => GoogleAdsChecks::LABELS,
        ]);
    }
}
