<?php

namespace App\Livewire\Operator\Settings;

use App\Services\Operations\AutoDeployStatus;
use App\Services\Operations\ReleaseInfo;
use App\Services\Operations\ReleaseLog;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Ayarlar › Sürümler (Admin): which version is live, every deploy with the changes it brought live, and the changes
 * pushed but not live yet (with the automatic deploy's state: in tests, stopped and why).
 */
#[Layout('operator.layouts.app')]
#[Title('Sürümler')]
final class ReleasesPage extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->hasRole(Roles::ADMIN), 403);
    }

    public function render(): View
    {
        return view('livewire.operator.settings.releases', [
            'release' => ReleaseInfo::current(),
            'autoDeploy' => AutoDeployStatus::current(),
            'deploys' => ReleaseLog::deploys(),
            'pending' => ReleaseLog::pending(),
        ]);
    }
}
