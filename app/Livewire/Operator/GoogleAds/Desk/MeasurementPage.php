<?php

namespace App\Livewire\Operator\GoogleAds\Desk;

use App\Models\BrandConversionSource;
use App\Services\GoogleAds\Desk\GoogleAdsDesk;
use App\Services\Measurement\BrandConversionDictionary;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Google Ads › Ölçüm (`/google-ads/olcum`): per account what Google Ads counts as a conversion (primary actions, a
 * page-view / engagement primary that inflates bidding, a missing phone conversion, the last conversion day), the
 * measurement system checks, whether last month's real results (form / uygun / randevu / satış) were entered, and
 * which share of the conversions are customers by the brand dictionary (the share Kazananlar compares brands on).
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

    public function render(GoogleAdsDesk $desk, BrandConversionDictionary $dictionary): View
    {
        $to = CarbonImmutable::yesterday();
        $shares = [];
        $rows = array_map(function (array $row) use ($dictionary, $to, &$shares): array {
            $brand = $row['asset']->brand;
            if ($brand !== null && ! array_key_exists($brand->id, $shares)) {
                $shares[$brand->id] = $dictionary->customerShare($brand, BrandConversionSource::SOURCE_GOOGLE_ADS, $to->subDays(29), $to);
            }

            return $row + ['customer_share' => $brand !== null ? $shares[$brand->id] : null];
        }, $desk->measurement($this->scopedAccounts()));

        return view('livewire.operator.google-ads.desk.measurement', [
            'rows' => $rows,
            'brandOptions' => $desk->brandOptions(),
        ]);
    }
}
