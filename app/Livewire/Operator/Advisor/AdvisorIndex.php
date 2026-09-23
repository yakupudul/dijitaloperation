<?php

namespace App\Livewire\Operator\Advisor;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * /ads-advisor — advisor items across every connected Google Ads, Meta Ads and Business Profile account.
 */
#[Layout('operator.layouts.app')]
#[Title('Danışman')]
final class AdvisorIndex extends Component
{
    public function render(): View
    {
        return view('livewire.operator.advisor.advisor-index');
    }
}
