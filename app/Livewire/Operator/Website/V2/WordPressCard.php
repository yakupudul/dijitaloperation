<?php

namespace App\Livewire\Operator\Website\V2;

use App\Livewire\Operator\Website\V2\Concerns\WebsiteTab;
use App\Models\CoreConnection;
use App\Models\ExternalWriteAction;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Integrations\WordPress\WordPressManagementService;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Web sitesi › Ayarlar › WordPress (moved here from the old Entegrasyonlar › WordPress siteleri screen): WordPress and
 * PHP versions, Site Health, pending core / plugin / theme updates with approved "Güncelle" (Admin, not undoable),
 * one-click "WP paneline gir" and the last updates. Shown only on a site with a paired connector.
 */
final class WordPressCard extends Component
{
    use WebsiteTab;

    public string $error = '';

    public function refreshHealth(WordPressManagementService $management): void
    {
        $this->actor();
        $data = $management->refreshHealth($this->site());
        $this->error = $data === null ? (string) DB::table('wordpress_site_health')->where('digital_asset_id', $this->websiteId)->value('error') : '';
        $this->message = $data !== null ? 'Sağlık bilgisi yenilendi.' : '';
    }

    public function applyUpdate(string $type, string $item, string $label, ExternalWriteService $writes): void
    {
        $actor = $this->actor();
        abort_unless($actor->hasRole(Roles::ADMIN), 403);
        try {
            $writes->requestUpdate($actor, $this->site(), $type, $item, $label);
            $this->error = '';
            $this->message = $label.' güncellemesi kuyruğa alındı. Geri alınamaz; sonucu aşağıda görünür.';
        } catch (ValidationException $exception) {
            $this->message = '';
            $this->error = (string) collect($exception->errors())->flatten()->first();
        }
    }

    public function render(): View
    {
        $this->actor();
        $connection = CoreConnection::query()->where('type', 'wordpress_connector')->where('enabled', true)->where('digital_asset_id', $this->websiteId)->first();
        $row = DB::table('wordpress_site_health')->where('digital_asset_id', $this->websiteId)->first();
        $version = (string) data_get($connection?->config, 'plugin_version', '');
        $minimum = (string) config('moxdop-wordpress.management_min_plugin_version', '1.3.0');

        return view('livewire.operator.website.v2.wordpress-card', [
            'connected' => $connection !== null,
            'managed' => $version !== '' && version_compare($version, $minimum, '>='),
            'minimum' => $minimum,
            'health' => $row !== null ? (array) json_decode((string) $row->payload, true) : null,
            'pending' => (int) ($row->pending_updates ?? 0),
            'critical' => (int) ($row->critical_issues ?? 0),
            'healthError' => $row->error ?? null,
            'checkedAt' => $row->checked_at ?? null,
            'actions' => $connection !== null ? ExternalWriteAction::query()->where('digital_asset_id', $this->websiteId)
                ->whereIn('action', [ExternalWriteAction::ACTION_UPDATE_APPLY, ExternalWriteAction::ACTION_CONNECTOR_UPDATE])->latest('id')->limit(10)->get() : collect(),
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
            'flashError' => session('wp_error'),
        ]);
    }
}
