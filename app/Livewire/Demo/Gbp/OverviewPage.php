<?php

namespace App\Livewire\Demo\Gbp;

use App\Contracts\GbpOperatorWorkspace;
use App\Livewire\Demo\Concerns\ResolvesCanonicalOperatorAsset;
use App\Models\DigitalAsset;
use App\Services\Async\AsyncOperationService;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Google Business Profile')]
class OverviewPage extends Component
{
    use ResolvesCanonicalOperatorAsset;

    public string $assetId = '';

    #[Url]
    public string $tab = 'overview';

    #[Url]
    public string $perf_sub = 'discovery';

    /** @var list<string> */
    public array $allowedTabs = [
        'overview',
        'profile',
        'visibility',
        'performance',
        'reviews',
        'competitors',
        'operations',
        'setup',
    ];

    /** @var array<string, string> */
    private const LEGACY_TAB_MAP = [
        'queries' => 'performance',
        'insights' => 'overview',
        'connections' => 'setup',
    ];

    public function mount(?string $assetId = null): void
    {
        $this->bindCanonicalAsset($assetId, ['google_business_profile', 'gbp']);
        $this->normalizeTab();
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->normalizeTab();
    }

    public function refreshData(AsyncOperationService $async): void
    {
        $result = $async->queueBoundCollect($this->asset(), auth()->user(), [
            'trigger' => 'operator.gbp.refresh',
        ]);

        DemoState::flash(
            (string) ($result['message'] ?? __('operator_runtime.sources.collect_failed')),
            ($result['ok'] ?? false) ? 'success' : 'info',
        );
    }

    protected function normalizeTab(): void
    {
        if (isset(self::LEGACY_TAB_MAP[$this->tab])) {
            $legacy = $this->tab;
            $this->tab = self::LEGACY_TAB_MAP[$legacy];

            if ($legacy === 'queries') {
                $this->perf_sub = 'queries';
            }
        }

        if (! in_array($this->tab, $this->allowedTabs, true)) {
            $this->tab = 'overview';
        }
    }

    public function render(GbpOperatorWorkspace $workspace): View
    {
        $this->normalizeTab();

        $asset = $this->asset()->loadMissing('brand');
        $data = $workspace->for($asset);

        return view('livewire.demo.gbp.overview', [
            'asset' => $this->presentCanonicalAsset(),
            'data' => $data,
            'identity' => $data['identity'],
            'flash' => DemoState::pullFlash(),
        ]);
    }

    private function asset(): DigitalAsset
    {
        return DigitalAsset::query()
            ->whereKey((int) $this->assetId)
            ->whereIn('type', ['google_business_profile', 'gbp'])
            ->firstOrFail();
    }
}
