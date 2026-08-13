<?php

namespace App\Livewire\Demo\Meta;

use App\Support\Demo\DemoCatalog;
use Livewire\Component;

/**
 * Thin redirect shim for legacy Meta Ads routes into the tabbed workspace.
 */
abstract class RedirectToWorkspace extends Component
{
    abstract protected function targetTab(): string;

    public function mount(string $assetId): void
    {
        $this->redirect(route('demo.meta.overview', [
            'assetId' => $assetId ?: DemoCatalog::META_ASSET_ID,
            'tab' => $this->targetTab(),
        ]), navigate: true);
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
