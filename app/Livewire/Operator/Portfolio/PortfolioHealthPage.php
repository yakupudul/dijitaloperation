<?php

namespace App\Livewire\Operator\Portfolio;

use App\Models\Customer;
use App\Services\Portfolio\CustomerCommercialSummary;
use App\Services\Portfolio\PortfolioHealthReader;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Portföy sağlığı: brand × channel grid (connected, current, alerts), coverage gaps and every customer's ad budget
 * pace for the month in one place.
 */
#[Layout('operator.layouts.app')]
#[Title('Portföy sağlığı')]
final class PortfolioHealthPage extends Component
{
    #[Url]
    public bool $onlyProblems = false;

    public function render(PortfolioHealthReader $reader, CustomerCommercialSummary $commercial): View
    {
        $health = $reader->read();
        $rows = $this->onlyProblems ? array_values(array_filter($health['rows'], fn (array $r): bool => $r['status'] !== 'ok')) : $health['rows'];
        $pacing = [];
        foreach (Customer::query()->with('brands')->where('status', 'active')->orderBy('name')->get() as $customer) {
            try {
                $summary = $commercial->for($customer);
            } catch (Throwable $error) {
                report($error);

                continue;
            }
            foreach ($summary['channels'] as $channel) {
                $pacing[] = ['customer_id' => $customer->id, 'customer' => $customer->name] + $channel;
            }
        }
        usort($pacing, fn (array $a, array $b): int => [['over' => 0, 'under' => 1, 'on_track' => 2, 'no_budget' => 3][$a['state']] ?? 4, $a['customer']] <=> [['over' => 0, 'under' => 1, 'on_track' => 2, 'no_budget' => 3][$b['state']] ?? 4, $b['customer']]);

        return view('livewire.operator.portfolio.portfolio-health', [
            'rows' => $rows,
            'gaps' => $health['gaps'],
            'unbound' => $health['unbound'],
            'totals' => $health['totals'],
            'channels' => PortfolioHealthReader::CHANNELS,
            'pacing' => $pacing,
        ]);
    }
}
