<?php

namespace App\Livewire\Demo\Instagram;

use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
use App\Support\Demo\InstagramWorkspaceFixtures;
use App\Support\Reality\DemoCatalogAssetGuard;
use App\Support\Reality\UnavailableWorkspaceShells;
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
    private const TABS = ['overview', 'profile', 'operations', 'setup'];

    /**
     * @var array<string, string>
     */
    private const LEGACY_TAB_MAP = [
        'relationships' => 'overview',
        'findings' => 'operations',
        'activity' => 'operations',
        'settings' => 'setup',
    ];

    public function mount(?string $assetId = null): void
    {
        if (is_string($assetId) && $assetId !== '') {
            $this->assetId = $assetId;
        }

        $this->normalizeTab();
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->normalizeTab();
    }

    private function normalizeTab(): void
    {
        if (isset(self::LEGACY_TAB_MAP[$this->tab])) {
            $this->tab = self::LEGACY_TAB_MAP[$this->tab];
        }

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'overview';
        }
    }

    public function render(): View
    {
        $this->normalizeTab();

        if (DemoCatalogAssetGuard::isDemoCatalogAssetId($this->assetId)
            || $this->assetId === InstagramWorkspaceFixtures::ASSET_ID) {
            $workspace = InstagramWorkspaceFixtures::workspace($this->assetId);

            return view('livewire.demo.instagram.overview', [
                'workspace' => $workspace,
                'brand' => DemoCatalog::brand(),
                'flash' => DemoState::pullFlash(),
            ]);
        }

        return view('livewire.demo.instagram.overview', [
            'workspace' => UnavailableWorkspaceShells::instagram($this->assetId),
            'brand' => null,
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
