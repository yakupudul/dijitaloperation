<?php

namespace App\Livewire\Demo\Integrations;

use App\Models\DiscoveryCandidate;
use App\Models\SocialAccount;
use App\Services\Integrations\OperatorIntegrationsHubQuery;
use App\Services\Operations\SystemHealthReader;
use App\Services\WhatsApp\WhatsAppConnection;
use App\Support\Demo\DemoState;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

#[Layout('operator.layouts.app')]
#[Title('Bağlantılar')]
class IntegrationsIndex extends Component
{
    use WithPagination;

    /**
     * Providers MoxDOP does not use, hidden from the hub (2026-10-19, owner decision): all AI runs on the Claude MCP
     * queue and DataForSEO is never added. Their routes and code stay.
     *
     * @var list<string>
     */
    public const array HIDDEN_PROVIDERS = [ProviderRegistry::DATAFORSEO, ProviderRegistry::OPENAI, ProviderRegistry::GEMINI, ProviderRegistry::GROQ, ProviderRegistry::OPENROUTER];

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
            $this->redirect(route('operator.integrations.site-connector', ['connector' => 'wordpress']), navigate: true);
        }
    }

    public function render(OperatorIntegrationsHubQuery $hub): View
    {
        return view('livewire.demo.integrations.integrations-index', [
            'groups' => $this->visibleGroups($hub->groups()),
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
            'ownChannels' => $this->ownChannels(),
        ]);
    }

    /**
     * Moximu's own channels: where WhatsApp, Instagram + Facebook and LinkedIn are connected (2026-11-08).
     *
     * @return list<array{key: string, name: string, ok: bool, state: string, hint: string, action: string, url: string}>
     */
    private function ownChannels(): array
    {
        try {
            $connection = app(WhatsAppConnection::class);
            $whatsapp = $connection->state($connection->integration());
            $platforms = SocialAccount::query()->where('enabled', true)->pluck('platform')->all();
        } catch (Throwable $e) {
            report($e);
            $whatsapp = 'setup';
            $platforms = [];
        }
        $meta = array_values(array_intersect(['facebook', 'instagram'], $platforms));
        $social = route('operator.social', ['sekme' => 'hesaplar']);

        return [
            ['key' => 'whatsapp', 'name' => 'WhatsApp', 'ok' => $whatsapp === 'connected',
                'state' => match ($whatsapp) {
                    'connected' => 'Bağlı', 'attention' => 'Bakılmalı', 'disabled' => 'Kapalı', default => 'Bağlı değil'
                },
                'hint' => 'İşletme numarası; mesajlar Gelen kutusuna düşer.', 'action' => $whatsapp === 'connected' ? 'Bağlantıyı aç' : 'Bağla',
                'url' => route('operator.whatsapp', ['tab' => 'setup'])],
            ['key' => 'meta', 'name' => 'Instagram ve Facebook', 'ok' => count($meta) === 2,
                'state' => match (count($meta)) {
                    2 => 'Bağlı', 1 => ucfirst($meta[0]).' bağlı', default => 'Bağlı değil'
                },
                'hint' => 'Gönderi yayını, yorumlar ve DM’ler; tek Meta girişi.', 'action' => count($meta) === 2 ? 'Hesapları aç' : 'Bağla',
                'url' => $social],
            ['key' => 'linkedin', 'name' => 'LinkedIn', 'ok' => in_array('linkedin', $platforms, true),
                'state' => in_array('linkedin', $platforms, true) ? 'Eklendi' : 'Eklenmedi',
                'hint' => 'Şirket sayfası; gönderiler “kopyala ve aç” ile paylaşılır.', 'action' => in_array('linkedin', $platforms, true) ? 'Hesapları aç' : 'Ekle',
                'url' => $social],
        ];
    }

    /**
     * The hub's groups without the hidden providers; a group left empty is dropped.
     *
     * @param  list<array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    private function visibleGroups(array $groups): array
    {
        $visible = [];
        foreach ($groups as $group) {
            $group['providers'] = array_values(array_filter(
                $group['providers'] ?? [],
                fn (array $provider): bool => ! in_array(strtolower((string) ($provider['id'] ?? '')), self::HIDDEN_PROVIDERS, true),
            ));
            if ($group['providers'] !== []) {
                $visible[] = $group;
            }
        }

        return $visible;
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
            $rows[] = ['tone' => 'warning', 'text' => $silent.' WordPress sitesinden 1 günden uzun süredir sinyal yok.', 'action' => 'Web siteleri', 'url' => route('operator.websites')];
        }
        if ($outdated > 0) {
            $rows[] = ['tone' => 'warning', 'text' => $outdated.' sitede WordPress eklentisi eski.', 'action' => 'Web siteleri', 'url' => route('operator.websites', ['durum' => 'eski'])];
        }
        if (($critical = count(array_filter($health['alerts'], fn (array $a): bool => $a['severity'] === 'critical'))) > 0) {
            $rows[] = ['tone' => 'error', 'text' => $critical.' kritik sistem uyarısı açık.', 'action' => 'Sistem Sağlığı', 'url' => $healthUrl];
        }
        usort($rows, fn (array $a, array $b): int => ($a['tone'] === 'error' ? 0 : 1) <=> ($b['tone'] === 'error' ? 0 : 1));

        return $rows;
    }
}
