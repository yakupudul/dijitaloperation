<?php

namespace App\Livewire\Operator\Panel;

use App\Models\User;
use App\Panel\Services\PanelDesk;
use App\Panel\Support\OperatorAccess;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** Bugün's customer panel line: what the brands' panels ask of the operator, each row opening the brand page at the right tab. */
final class PanelTodayCard extends Component
{
    public bool $all = false;

    public function render(PanelDesk $desk): View
    {
        $user = auth()->user();
        $rows = $user instanceof User && OperatorAccess::allowsSection($user, 'client_panel') ? $desk->rows() : collect();

        return view('livewire.operator.panel.today-card', ['rows' => $this->all ? $rows : $rows->take(6), 'count' => $rows->count()]);
    }

    public function placeholder(): string
    {
        return '<div></div>';
    }
}
