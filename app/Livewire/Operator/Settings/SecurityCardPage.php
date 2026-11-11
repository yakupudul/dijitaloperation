<?php

namespace App\Livewire\Operator\Settings;

use App\Services\Security\SecurityCard;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Ayarlar › Güvenlik karnesi (Admin, Bağlantı Düzeni 3.5): who can get in and what happens if the server is lost, one
 * question per row. Read only; each row links to the screen where it is fixed.
 */
#[Layout('operator.layouts.app')]
#[Title('Güvenlik karnesi')]
final class SecurityCardPage extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->hasRole(Roles::ADMIN), 403);
    }

    public function render(SecurityCard $card): View
    {
        $rows = $card->rows();
        // What needs him comes first: red, then yellow, then what is in order.
        usort($rows, fn (array $a, array $b): int => ['bad' => 0, 'warn' => 1, 'ok' => 2][$a['tone']] <=> ['bad' => 0, 'warn' => 1, 'ok' => 2][$b['tone']]);

        return view('livewire.operator.settings.security-card', ['rows' => $rows, 'totals' => SecurityCard::totals($rows)]);
    }
}
