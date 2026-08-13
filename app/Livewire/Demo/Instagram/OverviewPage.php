<?php

namespace App\Livewire\Demo\Instagram;

use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use App\Support\Demo\InstagramWorkspaceFixtures;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Instagram')]
class OverviewPage extends Component
{
    public string $assetId = InstagramWorkspaceFixtures::ASSET_ID;

    #[Url(as: 'tab', history: true)]
    public string $tab = 'overview';

    /**
     * @var list<string>
     */
    private const TABS = ['overview', 'profile', 'relationships', 'findings', 'activity', 'settings'];

    public function mount(?string $assetId = null): void
    {
        if (is_string($assetId) && $assetId !== '') {
            $this->assetId = $assetId;
        }

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'overview';
        }
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, self::TABS, true)) {
            $this->tab = $tab;
        }
    }

    public function render(): View
    {
        $workspace = InstagramWorkspaceFixtures::workspace($this->assetId);

        return view('livewire.demo.instagram.overview', [
            'workspace' => $workspace,
            'brand' => DemoCatalog::brand(),
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
