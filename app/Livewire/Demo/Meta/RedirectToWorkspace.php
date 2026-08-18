<?php

namespace App\Livewire\Demo\Meta;

use App\Support\Reality\OperatorCanonicalAsset;
use Livewire\Component;

/**
 * Thin redirect shim for legacy Meta Ads routes into the tabbed workspace.
 */
abstract class RedirectToWorkspace extends Component
{
    abstract protected function targetTab(): string;

    public function mount(string $assetId): void
    {
        $asset = OperatorCanonicalAsset::require($assetId, ['meta_ads']);

        $this->redirect(route('operator.meta.overview', [
            'assetId' => (string) $asset->id,
            'tab' => $this->targetTab(),
        ]), navigate: true);
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
