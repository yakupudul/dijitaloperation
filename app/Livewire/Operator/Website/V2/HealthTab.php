<?php

namespace App\Livewire\Operator\Website\V2;

use App\Jobs\Site\CheckSiteExpiryJob;
use App\Livewire\Operator\Website\V2\Concerns\WebsiteTab;
use App\Models\CoreConnection;
use App\Services\Site\Health\SiteHealthReader;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Site Sağlığı: WordPress update warnings + critical Site Health items (Connector), one-click WordPress login (admin,
 * existing audited path), SSL and domain expiry (daily automatic check), hosting expiry (manual date), uptime.
 * Each row: durum · tarih · tek satır.
 */
final class HealthTab extends Component
{
    use WebsiteTab;

    public string $hostingDate = '';

    public function saveHosting(): void
    {
        $this->actor();
        $this->validate(['hostingDate' => ['nullable', 'date_format:Y-m-d']], [], ['hostingDate' => 'hosting bitiş tarihi']);
        $site = $this->site();
        $site->forceFill(['hosting_expires_on' => $this->hostingDate !== '' ? $this->hostingDate : null])->save();
        $this->message = 'Kaydedildi.';
    }

    public function checkNow(): void
    {
        $this->actor();
        CheckSiteExpiryJob::dispatch($this->websiteId, true);
        $this->message = 'SSL ve alan adı kontrolü kuyrukta.';
    }

    public function render(SiteHealthReader $reader): View
    {
        $site = $this->site();
        if ($this->hostingDate === '' && $site->getAttribute('hosting_expires_on') !== null) {
            $this->hostingDate = substr((string) $site->getAttribute('hosting_expires_on'), 0, 10);
        }
        $user = auth()->user();

        return view('livewire.operator.website.v2.health-tab', [
            'site' => $site,
            'rows' => $reader->rows($site),
            'wordpress' => CoreConnection::query()->where('digital_asset_id', $site->id)->where('type', 'wordpress_connector')->where('enabled', true)->exists(),
            'isAdmin' => $user !== null && $user->hasRole(Roles::ADMIN),
        ]);
    }
}
