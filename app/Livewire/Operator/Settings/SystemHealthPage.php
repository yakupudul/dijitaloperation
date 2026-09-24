<?php

namespace App\Livewire\Operator\Settings;

use App\Services\Integrations\ResourceAutomationService;
use App\Services\Operations\SystemHealthReader;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Ayarlar › Sistem Sağlığı: scheduler and workers, open system alerts, integration authorizations and
 * expiry, every account's collection state and freshness, WordPress plugin versions.
 */
#[Layout('operator.layouts.app')]
#[Title('Sistem Sağlığı')]
final class SystemHealthPage extends Component
{
    public string $message = '';

    public bool $onlyProblems = true;

    /** Admin: make stopped collections due again now (same rules as the daily retry). */
    public function retryStopped(ResourceAutomationService $automations): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        $stats = $automations->retryStopped();
        $this->message = sprintf('%d hesap tekrar denenecek, %d hesap yeniden bağlandığı için devam edecek.', $stats['retried'], $stats['reconnected']);
    }

    /** Faz 13: per-row action of the accounts table (admin). */
    public function runNow(int $id, ResourceAutomationService $automations): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        $automations->runNow($id, auth()->user());
        $this->message = 'Hesap veri çekimi için sıraya alındı.';
    }

    public function render(SystemHealthReader $reader): View
    {
        $health = $reader->read();
        if ($this->onlyProblems) {
            $health['accounts'] = array_values(array_filter($health['accounts'], fn (array $a): bool => $a['state'] === 'attention' || $a['stale']));
        }

        return view('livewire.operator.settings.system-health', [
            'health' => $health,
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
        ]);
    }
}
