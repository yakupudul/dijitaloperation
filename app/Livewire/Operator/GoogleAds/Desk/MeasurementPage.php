<?php

namespace App\Livewire\Operator\GoogleAds\Desk;

use App\Services\GoogleAds\Desk\GoogleAdsDesk;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Google Ads › Ölçüm (`/google-ads/olcum`): per account what Google Ads counts as a conversion (primary actions, a
 * page-view / engagement primary that inflates bidding, a missing phone conversion, the last conversion day), the
 * measurement system checks and whether last month's real results (form / uygun / randevu / satış) were entered.
 */
#[Layout('operator.layouts.app')]
#[Title('Google Ads · Ölçüm')]
final class MeasurementPage extends Component
{
    use DeskScope;

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_active, 403);
    }

    public function render(GoogleAdsDesk $desk): View
    {
        return view('livewire.operator.google-ads.desk.measurement', [
            'rows' => $desk->measurement($this->scopedAccounts()),
            'brandOptions' => $desk->brandOptions(),
        ]);
    }
}
