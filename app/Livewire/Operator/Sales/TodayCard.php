<?php

namespace App\Livewire\Operator\Sales;

use App\Models\SalesLead;
use App\Services\Sales\SalesLeads;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** Komuta's "Bugün satış" box: the prospects whose next step is today, the first five with their step. */
final class TodayCard extends Component
{
    public function render(SalesLeads $leads): View
    {
        $due = $leads->due()->limit(5)->get();

        return view('livewire.operator.sales.today-card', [
            'due' => $due,
            'count' => $leads->due()->count(),
            'open' => SalesLead::query()->open()->count(),
        ]);
    }

    public function placeholder(): string
    {
        return '<div class="card" data-sales-today><div class="card-row"><p class="text-sm text-gray-500">Satış kutusu yükleniyor…</p></div></div>';
    }
}
