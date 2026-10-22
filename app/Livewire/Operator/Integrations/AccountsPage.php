<?php

namespace App\Livewire\Operator\Integrations;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Entegrasyonlar › Hesaplar (Aşama 6): the page around {@see AccountsTable} for every brand. */
#[Layout('operator.layouts.app')]
#[Title('Hesaplar')]
final class AccountsPage extends Component
{
    public function render(): View
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->is_active, 403);

        return view('livewire.operator.integrations.accounts-page');
    }
}
