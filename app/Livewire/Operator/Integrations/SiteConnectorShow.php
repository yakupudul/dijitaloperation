<?php

namespace App\Livewire\Operator\Integrations;

use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\User;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Integrations\WordPress\WordPressConnectorClient;
use App\Services\Integrations\WordPress\WordPressConnectorPackage;
use App\Services\Integrations\WordPress\WordPressConnectorPairingService;
use App\Services\Integrations\WordPress\WordPressSiteBuilder;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

#[Layout('operator.layouts.app')]
#[Title('WordPress bağlayıcısı')]
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

    /** @var array<int, string> reject reasons typed in the build log, by action id */
    public array $rejectReasons = [];

    /** 1.8.0: an Admin picks how Claude may build this site (MCP build-site): off, direct or approval. */
    public function setBuildMode(string $mode, WordPressSiteBuilder $builder): void
    {
        $asset = DigitalAsset::query()->where('type', 'website')->findOrFail($this->selectedAssetId);
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->hasRole(Roles::ADMIN), 403);

        try {
            $builder->setMode($asset, $actor, $mode === 'off' ? null : $mode);
            $this->messageTone = 'success';
            $this->message = match ($mode) {
                WordPressSiteBuilder::MODE_DIRECT => 'Claude bu sitede doğrudan kurulum yapar; her işlem aşağıdaki kayıtta, geri alınabilir.',
                WordPressSiteBuilder::MODE_APPROVAL => 'Claude\'un kurulumları aşağıdaki kayıtta onayını bekler.',
                default => 'Claude site kurulumu kapatıldı.',
            };
        } catch (Throwable $error) {
            $this->messageTone = 'error';
            $this->message = $error->getMessage();
        }
    }

    public function approveBuild(int $actionId, WordPressSiteBuilder $builder): void
    {
        $this->buildAction($actionId, fn (User $actor, ExternalWriteAction $action) => $builder->approve($actor, $action), 'Onaylandı; kurulum siteye gönderiliyor.');
    }

    public function rejectBuild(int $actionId, WordPressSiteBuilder $builder): void
    {
        $this->buildAction($actionId, fn (User $actor, ExternalWriteAction $action) => $builder->reject($actor, $action, $this->rejectReasons[$actionId] ?? null), 'Reddedildi; sitede bir şey değişmedi.');
        unset($this->rejectReasons[$actionId]);
    }

    public function undoBuild(int $actionId, ExternalWriteService $writes): void
    {
        $this->buildAction($actionId, fn (User $actor, ExternalWriteAction $action) => $writes->requestUndo($actor, $action), 'Geri alma başladı.');
    }

    public function forceUndoBuild(int $actionId, WordPressSiteBuilder $builder, ExternalWriteService $writes): void
    {
        $this->buildAction($actionId, function (User $actor, ExternalWriteAction $action) use ($builder, $writes): void {
            $builder->forceUndo($actor, $action);
            $writes->requestUndo($actor, $action->refresh());
        }, 'Geri alma başladı (sonradan yapılan değişikliklerin üzerine yazılacak).');
    }

    /** @param  callable(User, ExternalWriteAction): mixed  $run */
    private function buildAction(int $actionId, callable $run, string $done): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->hasRole(Roles::ADMIN), 403);
        $action = ExternalWriteAction::query()->where('digital_asset_id', $this->selectedAssetId)->where('action', ExternalWriteAction::ACTION_SITE_BUILD)->findOrFail($actionId);

        try {
            $run($actor, $action);
            $this->messageTone = 'success';
            $this->message = $done;
        } catch (ValidationException $error) {
            $this->messageTone = 'error';
            $this->message = (string) collect($error->errors())->flatten()->first();
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
            'buildMode' => WordPressSiteBuilder::mode($connection),
            'buildLog' => $connection ? app(WordPressSiteBuilder::class)->history((int) $selected->id) : [],
            'isAdmin' => auth()->user()?->hasRole(Roles::ADMIN) ?? false,
            'packageFilename' => $package->filename(),
        ]);
    }
}
