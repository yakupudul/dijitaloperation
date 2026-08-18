<?php

namespace App\Livewire\Operator\Website;

use App\Models\DigitalAsset;
use App\Services\Async\AsyncOperationService;
use App\Support\Reality\OperatorCanonicalAsset;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use MoxDop\Website\Workspace\WebsiteWorkspaceData;

#[Layout('operator.layouts.app')]
#[Title('Website')]
class OverviewPage extends Component
{
    public int $assetId;

    public string $message = '';

    public string $messageTone = 'info';

    public function mount(?string $assetId = null): void
    {
        $asset = OperatorCanonicalAsset::require($assetId, ['website']);
        $this->assetId = $asset->id;
    }

    public function refreshData(AsyncOperationService $async): void
    {
        $this->showResult($async->queueBoundCollect($this->asset(), auth()->user()));
    }

    public function runDiagnosis(AsyncOperationService $async): void
    {
        $this->showResult($async->queueWebsiteDiagnosis($this->asset(), auth()->user()));
    }

    public function refreshSeoIntelligence(AsyncOperationService $async): void
    {
        $this->showResult($async->queueSeoIntelligenceRefresh($this->asset(), auth()->user()));
    }

    public function generateAiGuidance(AsyncOperationService $async): void
    {
        $this->showResult($async->queueWebsiteAiGuidance($this->asset(), auth()->user()));
    }

    public function render(WebsiteWorkspaceData $workspace): View
    {
        $asset = $this->asset()->loadMissing('brand.customer');
        $data = $workspace->for($asset);

        return view('livewire.operator.website.overview', [
            'asset' => $asset,
            'brand' => $asset->brand,
            'customer' => $asset->brand?->customer,
            'data' => $data,
        ]);
    }

    /** @param array{ok: bool, message: string} $result */
    private function showResult(array $result): void
    {
        $this->message = (string) ($result['message'] ?? 'Operation queued.');
        $this->messageTone = ($result['ok'] ?? false) ? 'success' : 'info';
    }

    private function asset(): DigitalAsset
    {
        return DigitalAsset::query()->findOrFail($this->assetId);
    }
}
