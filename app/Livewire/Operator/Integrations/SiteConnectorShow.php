<?php

namespace App\Livewire\Operator\Integrations;

use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Integrations\WordPress\WordPressConnectorClient;
use App\Services\Integrations\WordPress\WordPressConnectorPackage;
use App\Services\Integrations\WordPress\WordPressConnectorPairingService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

#[Layout('operator.layouts.app')]
#[Title('WordPress Connector')]
final class SiteConnectorShow extends Component
{
    public string $connector = 'wordpress';

    #[Url(as: 'site', history: true)]
    public ?int $selectedAssetId = null;

    public ?string $pairingCode = null;

    public ?string $pairingExpiresAt = null;

    public string $message = '';

    public string $messageTone = 'info';

    public function mount(string $connector): void
    {
        abort_unless($connector === 'wordpress', 404);
        $this->connector = $connector;
        if ($this->selectedAssetId === null) {
            $this->selectedAssetId = DigitalAsset::query()->where('type', 'website')->orderBy('name')->value('id');
        }
    }

    public function selectAsset(int $assetId): void
    {
        DigitalAsset::query()->where('type', 'website')->findOrFail($assetId);
        $this->selectedAssetId = $assetId;
        $this->pairingCode = null;
        $this->pairingExpiresAt = null;
        $this->message = '';
    }

    public function issuePairingCode(WordPressConnectorPairingService $pairing): void
    {
        $asset = DigitalAsset::query()->where('type', 'website')->findOrFail($this->selectedAssetId);
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            $issued = $pairing->issue($asset, $actor);
            $this->pairingCode = $issued['code'];
            $this->pairingExpiresAt = $issued['expires_at']->toIso8601String();
            $this->messageTone = 'success';
            $this->message = 'Tek kullanımlık eşleştirme kodu üretildi.';
        } catch (Throwable $error) {
            report($error);
            $this->messageTone = 'error';
            $this->message = 'Eşleştirme kodu üretilemedi.';
        }
    }

    public function testConnection(WordPressConnectorClient $client): void
    {
        $connection = CoreConnection::query()
            ->with('credential')
            ->where('digital_asset_id', $this->selectedAssetId)
            ->where('type', WordPressConnectorPairingService::CONNECTION_TYPE)
            ->firstOrFail();
        try {
            $status = $client->status($connection);
            $this->messageTone = 'success';
            $this->message = 'Bağlantı doğrulandı: WordPress '.($status['wordpress_version'] ?? 'unknown').'.';
        } catch (Throwable $error) {
            report($error);
            $this->messageTone = 'error';
            $this->message = 'Connector bağlantısı doğrulanamadı.';
        }
    }

    public function disconnect(WordPressConnectorPairingService $pairing): void
    {
        $asset = DigitalAsset::query()->where('type', 'website')->findOrFail($this->selectedAssetId);
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            $pairing->revoke($asset, $actor);
            $this->pairingCode = null;
            $this->pairingExpiresAt = null;
            $this->messageTone = 'success';
            $this->message = 'Connector erişimi iptal edildi. WordPress eklentisindeki yerel eşleştirmeyi de kaldırabilirsiniz.';
        } catch (Throwable $error) {
            report($error);
            $this->messageTone = 'error';
            $this->message = 'Connector erişimi iptal edilemedi.';
        }
    }

    public function render(WordPressConnectorPackage $package): View
    {
        $assets = DigitalAsset::query()
            ->with(['brand', 'connections' => fn ($query) => $query
                ->where('type', WordPressConnectorPairingService::CONNECTION_TYPE)
                ->with('credential')])
            ->where('type', 'website')
            ->orderBy('name')
            ->get();
        $selected = $assets->firstWhere('id', $this->selectedAssetId);
        $connection = $selected?->connections->first();

        return view('livewire.operator.integrations.site-connector-show', [
            'assets' => $assets,
            'selected' => $selected,
            'connection' => $connection,
            'packageFilename' => $package->filename(),
        ]);
    }
}
