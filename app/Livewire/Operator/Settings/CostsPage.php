<?php

namespace App\Livewire\Operator\Settings;

use App\Services\Operations\CostReader;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Ayarlar › Maliyetler: monthly AI and DataForSEO spend recorded by the app (admin only).
 */
#[Layout('operator.layouts.app')]
#[Title('Maliyetler')]
final class CostsPage extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
    }

    public function render(CostReader $reader): View
    {
        return view('livewire.operator.settings.costs', ['costs' => $reader->read()]);
    }
}
