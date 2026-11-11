<?php

namespace App\Livewire\Operator;

use App\Support\EmergencyStop;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Acil durdurma (Bağlantı Düzeni 3.3). mode "banner" (panel layout): nothing while writes go, a red strip with
 * "Devam ettir" on every screen while they are stopped. mode "button" (Hata merkezi): the button that stops them.
 */
final class EmergencyStopSwitch extends Component
{
    public string $mode = 'banner';

    public function stop(): void
    {
        $user = auth()->user();
        abort_unless($user !== null && $user->hasRole(Roles::ADMIN), 403);
        EmergencyStop::stop($user);
        $this->dispatch('emergency-stop-changed');
    }

    public function resume(): void
    {
        $user = auth()->user();
        abort_unless($user !== null && $user->hasRole(Roles::ADMIN), 403);
        EmergencyStop::resume();
        $this->dispatch('emergency-stop-changed');
    }

    #[On('emergency-stop-changed')]
    public function refresh(): void {}

    public function render(): View
    {
        return view('livewire.operator.emergency-stop-switch', [
            'since' => EmergencyStop::since(),
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
        ]);
    }
}
