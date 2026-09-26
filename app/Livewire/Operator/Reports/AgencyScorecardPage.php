<?php

namespace App\Livewire\Operator\Reports;

use App\Services\Operations\AgencyScorecard;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Ajans karnesi: the month's work across all brands and the measured effect of earlier work. */
#[Layout('operator.layouts.app')]
#[Title('Ajans karnesi')]
final class AgencyScorecardPage extends Component
{
    #[Url]
    public string $month = '';

    public function render(AgencyScorecard $scorecard): View
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) !== 1) {
            $this->month = now()->format('Y-m');
        }
        $months = [];
        for ($i = 0; $i < 13; $i++) {
            $key = now()->startOfMonth()->subMonthsNoOverflow($i)->format('Y-m');
            $months[$key] = $key;
        }

        return view('livewire.operator.reports.agency-scorecard', ['data' => $scorecard->month($this->month), 'months' => $months]);
    }
}
