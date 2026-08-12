<?php

namespace App\Livewire\Operator\Meta;

use App\Models\CoreIntegration;
use App\Models\Run;
use App\Models\User;
use App\Services\Async\AsyncOperationService;
use App\Services\Integrations\Meta\MetaConnectionService;
use App\Services\Integrations\Meta\MetaCredentialResolver;
use App\Services\Integrations\Meta\MetaResourceDiscoveryService;
use App\Support\Async\AsyncOperationTypes;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use MoxDop\MetaAds\Support\MetaImportOverview;

#[Layout('operator.layouts.app')]
#[Title('Meta Integration')]
class IntegrationPage extends Component
{
    public ?int $integrationId = null;

    /** Currently expanded account detail (CoreExternalResource id), or null. */
    public ?int $selectedAccountId = null;

    public ?string $flashMessage = null;

    public ?string $flashTone = null;

    public function mount(): void
    {
        $this->integrationId = CoreIntegration::query()
            ->where('provider', ProviderRegistry::META)
            ->where('status', '!=', CoreIntegration::STATUS_DISABLED)
            ->orderBy('id')
            ->value('id');
    }

    private function integration(): ?CoreIntegration
    {
        return $this->integrationId !== null
            ? CoreIntegration::query()->find($this->integrationId)
            : null;
    }

    public function testConnection(): void
    {
        $integration = $this->integration();
        if ($integration === null) {
            $this->flash('No Meta integration is configured.', 'error');

            return;
        }

        $result = app(MetaConnectionService::class)->testConnection($integration);
        $this->flash($result['message'], ($result['ok'] ?? false) ? 'success' : 'error');
    }

    public function discoverAccounts(): void
    {
        $integration = $this->integration();
        if ($integration === null) {
            $this->flash('No Meta integration is configured.', 'error');

            return;
        }

        $result = app(MetaResourceDiscoveryService::class)->discover($integration);
        $count = (int) ($result['count'] ?? 0);
        $this->flash(
            ($result['message'] ?? 'Discovery finished.').' '.$count.' Ad Account(s) available.',
            ($result['ok'] ?? false) ? 'success' : 'error',
        );
    }

    public function importHistory(): void
    {
        $integration = $this->integration();
        if ($integration === null) {
            $this->flash('No Meta integration is configured.', 'error');

            return;
        }

        $user = auth()->user();
        $result = app(AsyncOperationService::class)->queueMetaHistoryImport(
            $integration,
            $user instanceof User ? $user : null,
        );

        $this->flash($result['message'], ($result['queued'] ?? false) ? 'success' : 'info');
    }

    public function toggleAccount(int $resourceId): void
    {
        $this->selectedAccountId = $this->selectedAccountId === $resourceId ? null : $resourceId;
    }

    private function flash(string $message, string $tone): void
    {
        $this->flashMessage = $message;
        $this->flashTone = $tone;
    }

    public function render(): View
    {
        $integration = $this->integration();

        $configured = $integration !== null
            && app(MetaCredentialResolver::class)->isConfigured($integration);

        $overview = $integration !== null
            ? MetaImportOverview::forIntegration($integration)
            : null;

        $activeImport = $integration !== null
            ? app(AsyncOperationService::class)->activeRunForIntegration(
                $integration->id,
                AsyncOperationTypes::META_HISTORY_IMPORT,
            )
            : null;

        $connectionStatus = $integration !== null
            ? (string) data_get($integration->config, 'connection_status', $configured ? 'configured' : 'not_configured')
            : 'not_configured';

        return view('livewire.operator.meta.integration-page', [
            'integration' => $integration,
            'configured' => $configured,
            'connectionStatus' => $connectionStatus,
            'overview' => $overview,
            'activeImport' => $activeImport instanceof Run ? $activeImport : null,
        ]);
    }
}
