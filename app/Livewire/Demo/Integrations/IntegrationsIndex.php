<?php

namespace App\Livewire\Demo\Integrations;

use App\Models\DiscoveryCandidate;
use App\Services\Integrations\OperatorIntegrationsHubQuery;
use App\Services\Operations\SystemHealthReader;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('operator.layouts.app')]
#[Title('Integrations')]
class IntegrationsIndex extends Component
{
    use WithPagination;

    #[Url]
    public ?int $discoveryBrand = null;

    public string $profileSearch = '';

    public function updatedProfileSearch(): void
    {
        $this->resetPage('profilesPage');
    }

    #[Url(as: 'section', history: true)]
    public string $section = '';

    public function mount(): void
    {
        if ($this->section === 'site_connectors') {
            $this->redirect(route('operator.integrations.site-connectors'), navigate: true);
        }
    }

    public function render(OperatorIntegrationsHubQuery $hub): View
    {
        return view('livewire.demo.integrations.integrations-index', [
            'groups' => $hub->groups(),
            'problems' => $this->problems(),
            'discoveredProfiles' => DiscoveryCandidate::query()->with(['brand', 'digitalAsset'])
                ->where('status', 'accepted')->where('target_field', 'social_links')
                ->where('support_json->application->state', 'integration_ready')
                ->whereHas('digitalAsset', fn ($query) => $query->whereColumn('digital_assets.brand_id', 'discovery_candidates.brand_id'))
                ->when($this->discoveryBrand, fn ($query) => $query->where('brand_id', $this->discoveryBrand))
                ->when(trim($this->profileSearch) !== '', fn ($query) => $query->where(fn ($nested) => $nested
                    ->where('accepted_value', 'like', '%'.trim($this->profileSearch).'%')
                    ->orWhereHas('brand', fn ($brand) => $brand->where('name', 'like', '%'.trim($this->profileSearch).'%'))))
                ->latest('reviewed_at')->paginate(10, pageName: 'profilesPage'),
            'flash' => DemoState::pullFlash(),
        ]);
    }

    /**
     * Faz 13: the hub opens with what needs action (red first): stopped or stale accounts, authorizations that
     * need reconnecting or expire within 7 days, silent / outdated WordPress plugins, open system alerts.
     *
     * @return list<array{tone: string, text: string, action: string, url: string}>
     */
    private function problems(): array
    {
        $health = app(SystemHealthReader::class)->read();
        $rows = [];
        foreach ($health['integrations'] as $integration) {
            $name = ucfirst($integration['provider']);
            $route = match ($integration['provider']) {
                'google' => route('operator.integrations.google'),
                'meta' => route('operator.integrations.meta'),
                default => route('operator.integrations'),
            };
            if (in_array($integration['provider'], ['google', 'meta'], true) && $integration['auth_status'] !== '' && $integration['auth_status'] !== 'connected') {
                $rows[] = ['tone' => 'error', 'text' => $name.' bağlantısı yenilenmeli.', 'action' => 'Yeniden bağlan', 'url' => $route];
            } elseif ($integration['expires_in_days'] !== null && $integration['expires_in_days'] <= 7) {
                $rows[] = ['tone' => $integration['expires_in_days'] < 0 ? 'error' : 'warning', 'text' => $name.' yetkisi '.($integration['expires_in_days'] < 0 ? 'doldu' : $integration['expires_in_days'].' gün içinde doluyor').' ('.$integration['expires_at'].').', 'action' => 'Yeniden bağlan', 'url' => $route];
            }
        }
        $stopped = count(array_filter($health['accounts'], fn (array $a): bool => $a['enabled'] && $a['state'] === 'attention'));
        $stale = count(array_filter($health['accounts'], fn (array $a): bool => $a['stale'] && $a['state'] !== 'attention'));
        $healthUrl = route('operator.settings.system-health');
        if ($stopped > 0) {
            $rows[] = ['tone' => 'error', 'text' => $stopped.' hesapta otomatik veri çekimi durdu.', 'action' => 'Hesapları gör', 'url' => $healthUrl];
        }
        if ($stale > 0) {
            $rows[] = ['tone' => 'warning', 'text' => $stale.' hesabın verisi eski.', 'action' => 'Hesapları gör', 'url' => $healthUrl];
        }
        $silent = count(array_filter($health['plugins'], fn (array $p): bool => $p['silent']));
        $outdated = count(array_filter($health['plugins'], fn (array $p): bool => $p['outdated']));
        if ($silent > 0) {
            $rows[] = ['tone' => 'warning', 'text' => $silent.' WordPress sitesinden 1 günden uzun süredir sinyal yok.', 'action' => 'WordPress siteleri', 'url' => route('operator.integrations.wordpress-sites')];
        }
        if ($outdated > 0) {
            $rows[] = ['tone' => 'warning', 'text' => $outdated.' sitede WordPress eklentisi eski.', 'action' => 'WordPress siteleri', 'url' => route('operator.integrations.wordpress-sites')];
        }
        if (($critical = count(array_filter($health['alerts'], fn (array $a): bool => $a['severity'] === 'critical'))) > 0) {
            $rows[] = ['tone' => 'error', 'text' => $critical.' kritik sistem uyarısı açık.', 'action' => 'Sistem Sağlığı', 'url' => $healthUrl];
        }
        usort($rows, fn (array $a, array $b): int => ($a['tone'] === 'error' ? 0 : 1) <=> ($b['tone'] === 'error' ? 0 : 1));

        return $rows;
    }
}
