<?php

namespace App\Livewire\Demo\Instagram;

use App\Livewire\Demo\Concerns\ResolvesCanonicalOperatorAsset;
use App\Support\Demo\DemoState;
use App\Support\Reality\UnavailableWorkspaceShells;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Single-screen Instagram asset page. Instagram analytics are not collected yet, so the page states
 * that plainly and only shows the last read-only profile collection when one exists.
 */
#[Layout('operator.layouts.app')]
#[Title('Instagram')]
class OverviewPage extends Component
{
    use ResolvesCanonicalOperatorAsset;

    public string $assetId = '';

    public function mount(?string $assetId = null): void
    {
        $this->bindCanonicalAsset($assetId, ['instagram']);
    }

    /**
     * The page used to have tabs; old links and calls that still switch tabs simply stay on this screen.
     */
    public function setTab(string $tab): void {}

    public function render(): View
    {
        return view('livewire.demo.instagram.overview', [
            'workspace' => UnavailableWorkspaceShells::instagram($this->assetId),
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
