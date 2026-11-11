<?php

namespace App\Livewire\Operator\Portfolio;

use App\Models\Brand;
use App\Services\Sales\Agreements;
use App\Services\Sales\CustomerSignals;
use App\Support\Demo\DemoMenu;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The one-line commercial strip on a brand's page (Aşama 3): the agreement, which month, this month's payment, the last
 * report, the last contact and the open risk signals. Read from Satış; edited there, never here.
 */
final class BrandCommercialStrip extends Component
{
    public int $brandId;

    public function render(Agreements $agreements, CustomerSignals $signals): View
    {
        $brand = Brand::query()->with('customer')->find($this->brandId);
        // The agency's own brand is nobody's customer: no agreement, payment or "son temas" line on its page.
        if ($brand === null || (int) (DemoMenu::agencyBrand()['id'] ?? 0) === (int) $brand->id) {
            return view('livewire.operator.portfolio.brand-commercial-strip', ['row' => null]);
        }
        $summary = $agreements->summary($brand);
        $previous = now('Europe/Istanbul')->startOfMonth()->subMonth();
        $last = $signals->lastContactAt($brand);

        return view('livewire.operator.portfolio.brand-commercial-strip', ['row' => [
            'brand' => $brand, 'agreement' => $summary['agreement'], 'month_no' => CustomerSignals::monthNo($summary['agreement']),
            'this_month' => $summary['payments']->firstWhere('period', now('Europe/Istanbul')->format('Y-m')),
            'report_month' => $previous->format('Y-m'), 'report' => $signals->report($brand, $previous->format('Y-m')),
            'last_contact' => $last, 'contact_days' => $last !== null ? (int) $last->startOfDay()->diffInDays(now('Europe/Istanbul')->startOfDay()) : null,
            'signals' => $signals->open($brand, $summary), 'setup' => $signals->setup($brand, $summary['agreement']),
        ]]);
    }

    public function placeholder(): string
    {
        return '<div class="h-6" data-brand-commercial></div>';
    }
}
