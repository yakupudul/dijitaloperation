<?php

namespace App\Livewire\Operator\Integrations;

use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Integrations\WordPress\WordPressManagementService;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Entegrasyonlar › WordPress siteleri (Faz 9c): every paired connector site with plugin / WordPress versions,
 * pending updates and Site Health; one-click admin login and approved updates (Admin, ADR-068).
 */
#[Layout('operator.layouts.app')]
#[Title('WordPress siteleri')]
final class WordPressSitesPage extends Component
{
    #[Url]
    public ?int $open = null;

    public string $message = '';

    public string $error = '';

    public function refreshHealth(int $siteId, WordPressManagementService $management): void
    {
        $site = DigitalAsset::query()->findOrFail($siteId);
        $data = $management->refreshHealth($site);
        $this->error = $data === null ? (string) DB::table('wordpress_site_health')->where('digital_asset_id', $siteId)->value('error') : '';
        $this->message = $data !== null ? 'Sağlık bilgisi yenilendi.' : '';
        $this->open = $siteId;
    }

    public function applyUpdate(int $siteId, string $type, string $item, string $label, ExternalWriteService $writes): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        try {
            $writes->requestUpdate(auth()->user(), DigitalAsset::query()->findOrFail($siteId), $type, $item, $label);
            $this->error = '';
            $this->message = $label.' güncellemesi kuyruğa alındı. Geri alınamaz; sonucu aşağıda görünür.';
        } catch (ValidationException $exception) {
            $this->message = '';
            $this->error = (string) collect($exception->errors())->flatten()->first();
        }
        $this->open = $siteId;
    }

    public function render(): View
    {
        $connections = CoreConnection::query()->where('type', 'wordpress_connector')->where('enabled', true)->whereNotNull('digital_asset_id')->get();
        $sites = DigitalAsset::query()->with('brand')->whereIn('id', $connections->pluck('digital_asset_id'))->orderBy('name')->get();
        $health = DB::table('wordpress_site_health')->whereIn('digital_asset_id', $sites->pluck('id'))->get()->keyBy('digital_asset_id');
        $minimum = (string) config('moxdop-wordpress.management_min_plugin_version', '1.3.0');

        return view('livewire.operator.integrations.wordpress-sites', [
            'rows' => $sites->map(function (DigitalAsset $site) use ($connections, $health, $minimum): array {
                $connection = $connections->firstWhere('digital_asset_id', $site->id);
                $version = (string) data_get($connection?->config, 'plugin_version', '');
                $row = $health->get($site->id);

                return [
                    'site' => $site,
                    'paired' => data_get($connection?->config, 'pairing_state') === 'paired',
                    'plugin_version' => $version,
                    'managed' => $version !== '' && version_compare($version, $minimum, '>='),
                    'health' => $row !== null ? (array) json_decode((string) $row->payload, true) : null,
                    'pending' => (int) ($row->pending_updates ?? 0),
                    'critical' => (int) ($row->critical_issues ?? 0),
                    'error' => $row->error ?? null,
                    'checked_at' => $row->checked_at ?? null,
                ];
            }),
            'actions' => $this->open !== null ? ExternalWriteAction::query()->where('digital_asset_id', $this->open)->where('action', ExternalWriteAction::ACTION_UPDATE_APPLY)->latest('id')->limit(10)->get() : collect(),
            'minimum' => $minimum,
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
            'flashError' => session('wp_error'),
        ]);
    }
}
