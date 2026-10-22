<?php

namespace App\Livewire\Operator\Settings;

use App\Jobs\Verification\RunLiveVerificationJob;
use App\Models\AgencySetting;
use App\Services\Integrations\ResourceAutomationService;
use App\Services\Observability\ErrorTriage;
use App\Services\Operations\RemoteBackupCopy;
use App\Services\Operations\ServerStatusReader;
use App\Services\Operations\SystemHealthReader;
use App\Services\Operator\AgencySettingService;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Hata merkezi (Ayarlar › Sistem Sağlığı): server memory / disk / load and software health on top, open problems split into "Senin işin", "Yazılım hatası" and "Sistem
 * hallediyor" (ErrorTriage, grouped by cause), then the technical details: scheduler and workers, integration
 * authorizations and expiry, brand-bound accounts' collection state and freshness, WordPress plugin versions.
 */
#[Layout('operator.layouts.app')]
#[Title('Hata merkezi')]
final class SystemHealthPage extends Component
{
    public string $message = '';

    public bool $onlyProblems = true;

    /** Uzak yedek (audit D5): a WebDAV folder, e.g. a Hetzner Storage Box; the password is never sent back to the page. */
    public string $remoteUrl = '';

    public string $remoteUsername = '';

    public string $remotePassword = '';

    public bool $editingRemote = false;

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

    public function editRemote(): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        $setting = AgencySetting::query()->orderBy('id')->first();
        $this->remoteUrl = (string) ($setting?->backup_remote_url ?? '');
        $this->remoteUsername = (string) ($setting?->backup_remote_username ?? '');
        $this->remotePassword = '';
        $this->editingRemote = true;
    }

    /** Saves the folder (an empty password keeps the stored one) and tries it at once. */
    public function saveRemote(RemoteBackupCopy $copy): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        $this->validate([
            'remoteUrl' => ['required', 'url:https', 'max:500'],
            'remoteUsername' => ['required', 'string', 'max:190'],
            'remotePassword' => ['nullable', 'string', 'max:500'],
        ], [], ['remoteUrl' => 'klasör adresi', 'remoteUsername' => 'kullanıcı adı', 'remotePassword' => 'şifre']);
        $setting = AgencySetting::query()->orderBy('id')->first() ?? new AgencySetting;
        $setting->backup_remote_url = rtrim(trim($this->remoteUrl), '/');
        $setting->backup_remote_username = trim($this->remoteUsername);
        if ($this->remotePassword !== '') {
            $setting->backup_remote_password = $this->remotePassword;
        }
        $setting->save();
        app(AgencySettingService::class)->forget();
        $this->remotePassword = '';
        $this->editingRemote = false;
        $this->message = 'Uzak yedek kaydedildi. '.$copy->check().' Bir sonraki gece yedeği oraya da kopyalanır.';
    }

    public function removeRemote(): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        AgencySetting::query()->orderBy('id')->first()?->forceFill(['backup_remote_url' => null, 'backup_remote_username' => null, 'backup_remote_password' => null])->save();
        app(AgencySettingService::class)->forget();
        $this->editingRemote = false;
        $this->message = 'Uzak yedek kapatıldı; yedekler yalnız sunucuda tutulur.';
    }

    public function toggleDatasets(int $automationId): void
    {
        $this->datasetsFor = $this->datasetsFor === $automationId ? null : $automationId;
    }

    public function render(SystemHealthReader $reader, ErrorTriage $triage, ServerStatusReader $server): View
    {
        $health = $reader->read();
        if ($this->onlyProblems) {
            $health['accounts'] = array_values(array_filter($health['accounts'], fn (array $a): bool => $a['state'] === 'attention' || $a['stale']));
        }

        return view('livewire.operator.settings.system-health', [
            'health' => $health,
            'triage' => $triage->groups(),
            'status' => $server->read(),
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
            'datasets' => $this->datasetsFor !== null ? $reader->datasets($this->datasetsFor) : [],
        ]);
    }
}
