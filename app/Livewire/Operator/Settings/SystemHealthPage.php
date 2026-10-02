<?php

namespace App\Livewire\Operator\Settings;

use App\Jobs\Verification\RunLiveVerificationJob;
use App\Services\Integrations\ResourceAutomationService;
use App\Services\Observability\ErrorTriage;
use App\Services\Operations\SystemHealthReader;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Hata merkezi (Ayarlar › Sistem Sağlığı): open problems split into "Senin işin", "Yazılım hatası" and "Sistem
 * hallediyor" (ErrorTriage, grouped by cause), then the technical details: scheduler and workers, integration
 * authorizations and expiry, brand-bound accounts' collection state and freshness, WordPress plugin versions.
 */
#[Layout('operator.layouts.app')]
#[Title('Hata merkezi')]
final class SystemHealthPage extends Component
{
    public string $message = '';

    public bool $onlyProblems = true;

    /** Faz 14: account whose per-dataset "data through" dates are shown. */
    public ?int $datasetsFor = null;

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

    /** "Şimdi güncelle" on a stopped account's alert (any operator; same as the account list's button). */
    public function runNowAutomation(int $id, ResourceAutomationService $automations): void
    {
        $automations->runNow($id, auth()->user());
        $this->message = 'Güncelleme sıraya alındı; birkaç dakika içinde başlar. Başarılı olunca uyarı kendiliğinden kapanır.';
    }

    /** Admin: queue the read-only live verification of every connection (moxdop:verify:live). */
    public function verifyNow(): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        RunLiveVerificationJob::dispatch();
        $this->message = 'Canlı doğrulama sıraya alındı; sonuçlar birkaç dakika içinde burada görünür.';
    }

    public function toggleDatasets(int $automationId): void
    {
        $this->datasetsFor = $this->datasetsFor === $automationId ? null : $automationId;
    }

    public function render(SystemHealthReader $reader, ErrorTriage $triage): View
    {
        $health = $reader->read();
        if ($this->onlyProblems) {
            $health['accounts'] = array_values(array_filter($health['accounts'], fn (array $a): bool => $a['state'] === 'attention' || $a['stale']));
        }

        return view('livewire.operator.settings.system-health', [
            'health' => $health,
            'triage' => $triage->groups(),
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
            'datasets' => $this->datasetsFor !== null ? $reader->datasets($this->datasetsFor) : [],
        ]);
    }
}
