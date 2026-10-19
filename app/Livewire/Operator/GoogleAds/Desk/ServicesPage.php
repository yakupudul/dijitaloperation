<?php

namespace App\Livewire\Operator\GoogleAds\Desk;

use App\Services\GoogleAds\Desk\GoogleAdsDesk;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Google Ads › Hizmet karşılaştırma (`/google-ads/hizmetler`): the same catalog service across brands, each brand's
 * Google Ads cost per conversion of the last 30 days (keyword → hizmet, AdServiceStats) against the brands' median.
 */
#[Layout('operator.layouts.app')]
#[Title('Google Ads · Hizmet karşılaştırma')]
final class ServicesPage extends Component
{
    use DeskScope;

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_active, 403);
    }

    public function render(GoogleAdsDesk $desk): View
    {
        return view('livewire.operator.google-ads.desk.services', [
            'services' => $desk->services($this->brand),
            'periodEnd' => GoogleAdsDesk::servicesAt(),
            'brandOptions' => $desk->brandOptions(),
        ]);
    }
}
