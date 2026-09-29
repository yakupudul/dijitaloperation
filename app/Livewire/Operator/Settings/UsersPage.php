<?php

namespace App\Livewire\Operator\Settings;

use App\Services\Operator\OperatorUserDirectory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Ayarlar › Kullanıcılar: the operator team (name, e-mail, role, last login). Editing stays on Ayarlar › Ekip. */
#[Layout('operator.layouts.app')]
#[Title('Kullanıcılar')]
final class UsersPage extends Component
{
    public function render(): View
    {
        return view('livewire.operator.settings.users', [
            'members' => OperatorUserDirectory::presentationMembers(),
        ]);
    }
}
