<?php

namespace App\Services\BrandSetup;

use App\Models\Brand;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreConnection;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Services\DataStatus\DataStatus;
use App\Services\DataStatus\DataStatusReader;
use App\Services\Integrations\BrandAccountCandidates;
use App\Services\Integrations\Google\GoogleCredentialResolver;
use App\Services\Integrations\Meta\MetaCredentialResolver;
use App\Services\Integrations\Meta\SelectMetaDiscoveryContextService;
use App\Services\Integrations\WordPress\WordPressConnectorPairingService;
use App\Support\Integrations\Google\GoogleAuthStatus;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Support\Collection;
use Throwable;

/**
 * "Kurulum durumu" on the brand page: per channel (website, Google Ads, Meta Ads) the steps from integration to daily
 * use, each done / next / waiting, and a one-click action for the next step. Read from the real state (integrations,
 * discovered accounts, bindings, data status, crawl / SEO plan / advisor runs); nothing is written here.
 *
 * Step states: done; next (the first open step — it carries the action); waiting (needs an earlier step first);
 * optional (not required, action offered). Ad channels the brand does not use (no bound account, no candidate) are
 * marked unused and do not count against the total.
 *
 * Actions: ['kind' => 'link', 'url' => …] or ['kind' => 'call', 'method' => BrandShow method, 'arg' => …].
 */
final class BrandSetupStatus
{
    public function __construct(
        private readonly DataStatusReader $dataStatus,
        private readonly BrandAccountCandidates $candidates,
    ) {}

    /**
     * @return array{channels: list<array<string, mixed>>, done: int, total: int, complete: bool, candidates: list<array<string, mixed>>}
     */
    public function for(Brand $brand): array
    {
        $assets = $brand->digitalAssets()->where('status', 'active')->with(['assetBindings' => fn ($q) => $q->where('status', CoreAssetBinding::STATUS_ACTIVE)])->orderBy('id')->get();
        try {
            $statuses = $this->dataStatus->forAssets($assets);
        } catch (Throwable $error) {
            report($error);
            $statuses = [];
        }
        $candidates = $this->candidates->forBrand($brand);
        $google = CoreIntegration::query()->with(['authorizationCredential', 'providerCredential'])->where('provider', ProviderRegistry::GOOGLE)->first();
        $meta = CoreIntegration::query()->with('providerCredential')->where('provider', ProviderRegistry::META)->first();

        $channels = [
            $this->website($brand, $assets, $statuses, $google),
            $this->ads('google_ads', 'Google Ads', $brand, $assets, $statuses, $candidates, $this->googleSteps($google)),
            $this->ads('meta_ads', 'Meta Ads', $brand, $assets, $statuses, $candidates, $this->metaSteps($meta)),
        ];
        $counted = array_filter($channels, fn (array $c): bool => ! $c['unused']);
        $done = count(array_filter($counted, fn (array $c): bool => $c['complete']));

        return ['channels' => $channels, 'done' => $done, 'total' => count($counted), 'complete' => $done === count($counted), 'candidates' => $candidates];
    }

    /**
     * @param  Collection<int, DigitalAsset>  $assets
     * @param  array<int, list<DataStatus>>  $statuses
     * @return array<string, mixed>
     */
    private function website(Brand $brand, Collection $assets, array $statuses, ?CoreIntegration $google): array
    {
        $site = $assets->firstWhere('type', 'website');
        $bound = fn (string $capability): bool => $site !== null && $site->assetBindings->contains('capability', $capability);
        $googleReady = $google !== null && $google->isActive() && GoogleAuthStatus::for($google) === GoogleAuthStatus::CONNECTED;
        $sources = $site !== null ? ['link', route('operator.asset.sources', ['assetId' => $site->id]), 'Veri kaynaklarını aç'] : null;

        $steps = [];
        $steps[] = $this->step('site', 'Web sitesi varlığı', $site !== null,
            $site !== null ? (string) ($site->primary_url ?: $site->domain ?: $site->name) : 'Adresi girin; "Otomatik kur" siteyi, Search Console / GA4 hesaplarını ve hizmetleri birlikte önerir.',
            ['link', route('operator.brand.setup', ['brand' => $brand->id]), 'Otomatik kur']);
        $steps[] = $this->step('google', 'Google bağlantısı', $googleReady,
            $googleReady ? 'Bağlı.' : 'Search Console, GA4 ve Google Ads için bir kez Google ile izin verin (ajans geneli).',
            ['link', route('operator.integrations.google'), 'Google ile bağlan']);
        $steps[] = $this->step('search_console', 'Search Console', $bound('search_console'),
            $bound('search_console') ? $this->sourceDetail($statuses, $site, 'search_console') : 'Siteye Search Console mülkünü bağlayın; ilk SEO planı kendiliğinden kuyruğa alınır.', $sources);
        $steps[] = $this->step('ga4', 'Google Analytics 4', $bound('ga4'),
            $bound('ga4') ? $this->sourceDetail($statuses, $site, 'ga4') : 'Siteye GA4 mülkünü bağlayın.', $sources);

        $isWordPress = $site !== null && str_contains(strtolower((string) $site->cms), 'wordpress');
        $paired = $site !== null && CoreConnection::query()->where('digital_asset_id', $site->id)->where('type', WordPressConnectorPairingService::CONNECTION_TYPE)
            ->where('enabled', true)->where('config->pairing_state', WordPressConnectorPairingService::PAIRED)->exists();
        $steps[] = $this->step('wordpress', 'WordPress eklentisi', $paired,
            $paired ? 'Eşleşti; değişiklikler anında bildirilir, düzeltmeler tek tıkla uygulanır.' : ($isWordPress ? 'Site WordPress: eklentiyi kurup eşleştirin (düzeltmeler ve anlık değişiklik takibi için).' : 'Yalnız WordPress sitelerde gerekir.'),
            $site !== null ? ['link', route('operator.integrations.site-connector', ['connector' => 'wordpress', 'site' => $site->id]), 'Eklentiyi eşleştir'] : null,
            optional: ! $isWordPress);

        $crawl = $site !== null ? $this->lastCrawl($site) : null;
        $crawled = in_array($crawl, ['completed', 'partial'], true);
        $steps[] = $this->step('crawl', 'İlk site taraması', $crawled,
            match (true) {
                $crawled => 'Tarandı; sonra her saat site haritası değişikliği izlenir.',
                in_array($crawl, ['queued', 'running', 'retrying'], true) => 'Tarama sürüyor; birkaç dakika sürer.',
                $crawl === 'failed' => 'Son tarama başarısız oldu; yeniden başlatın.',
                default => 'Sayfalar ve teknik sorunlar için siteyi bir kez tarayın.',
            },
            in_array($crawl, ['queued', 'running', 'retrying'], true) ? null : ['call', 'setupStartCrawl', null, 'Taramayı başlat']);

        return $this->channel('website', 'Web sitesi', $steps, false,
            $site !== null ? ['label' => 'Web sitesi', 'url' => route('operator.website', ['assetId' => $site->id])] : null);
    }

    /**
     * @param  Collection<int, DigitalAsset>  $assets
     * @param  array<int, list<DataStatus>>  $statuses
     * @param  list<array<string, mixed>>  $candidates
     * @param  list<array<string, mixed>>  $integrationSteps
     * @return array<string, mixed>
     */
    private function ads(string $type, string $label, Brand $brand, Collection $assets, array $statuses, array $candidates, array $integrationSteps): array
    {
        $accounts = $assets->where('type', $type)->filter(fn (DigitalAsset $a): bool => $a->assetBindings->contains('capability', $type))->values();
        $open = array_values(array_filter($candidates, fn (array $c): bool => $c['type'] === $type));
        $discovered = CoreExternalResource::query()->where('resource_type', $type)->where('status', CoreExternalResource::STATUS_AVAILABLE)->get(['metadata'])
            ->contains(fn (CoreExternalResource $r): bool => ($r->metadata['is_manager'] ?? false) !== true);
        $provider = $type === 'meta_ads' ? 'meta' : 'google';

        $steps = $integrationSteps;
        $steps[] = $this->step('discover', 'Hesapları listele', $discovered,
            $discovered ? 'Hesaplar listelendi; her gece yeniden kontrol edilir.' : 'Bağlantıdan erişilen bütün reklam hesaplarını listeler.',
            ['call', 'setupDiscover', $provider, 'Hesapları listele']);
        $bindDetail = $accounts->isNotEmpty()
            ? $accounts->count().' hesap bağlı: '.$accounts->pluck('name')->implode(', ')
            : 'Markanın reklam hesabını seçin; her hesap ayrı bir varlık olur.';
        if ($open !== []) {
            $bindDetail .= ' · '.count($open).' bağlanmamış hesap bulundu.';
        }
        $steps[] = $this->step('bind', 'Hesap bağla', $accounts->isNotEmpty(), $bindDetail,
            ['link', route('operator.brand', ['brand' => $brand->id, 'tab' => 'varliklar']).'#hesap-ekle', $open !== [] ? 'Hesap ekle ('.count($open).')' : 'Hesap ekle']);
        // More unbound accounts of the brand's business: keep the action visible even when one account is bound.
        $steps[array_key_last($steps)]['always'] = $open !== [];

        $data = $accounts->map(fn (DigitalAsset $a): ?DataStatus => collect($statuses[(int) $a->id] ?? [])->first(fn (DataStatus $s): bool => $s->capability === $type))->filter();
        $problem = $data->first(fn (DataStatus $s): bool => $s->state === DataStatus::ACCESS_PROBLEM);
        $loading = $data->contains(fn (DataStatus $s): bool => $s->state === DataStatus::FIRST_LOAD);
        $hasData = $accounts->isNotEmpty() && $data->count() === $accounts->count() && $data->every(fn (DataStatus $s): bool => $s->hasData() || $s->state === DataStatus::PAUSED);
        $steps[] = $this->step('data', 'İlk veri (13 ay)', $hasData,
            match (true) {
                $problem !== null => 'Erişim sorunu: '.$problem->detail(),
                $hasData => 'Veri geliyor; aktif hesaplar her gün güncellenir.',
                $loading => 'İlk veri yükleniyor; hesap başına birkaç dakika ile birkaç saat sürer.',
                default => 'Bağlanınca birkaç dakika içinde kendiliğinden başlar.',
            },
            $problem !== null && $problem->actionUrl !== null ? ['link', $problem->actionUrl, 'Yeniden bağlan'] : ['call', 'setupCollectNow', $type, 'Şimdi çek']);

        $first = $accounts->first();
        $route = $type === 'meta_ads' ? 'operator.meta.overview' : 'operator.google-ads.overview';
        $channel = $this->channel($type, $label, $steps, $accounts->isEmpty() && $open === [],
            $first !== null ? ['label' => $type === 'google_ads' ? 'Danışman önerileri ve Editor dışa aktarımı' : 'Danışman önerileri', 'url' => route($route, ['assetId' => $first->id, 'tab' => 'advisor'])] : null);
        $channel['accounts'] = $accounts->count();

        return $channel;
    }

    /** @return list<array<string, mixed>> */
    private function googleSteps(?CoreIntegration $google): array
    {
        $connected = $google !== null && $google->isActive() && GoogleAuthStatus::for($google) === GoogleAuthStatus::CONNECTED;
        $token = $google !== null && app(GoogleCredentialResolver::class)->developerToken($google) !== null;

        return [$this->step('connect', 'Google bağlantısı', $connected && $token,
            match (true) {
                $connected && $token => 'Bağlı.',
                $connected => 'Google bağlı ama Google Ads geliştirici jetonu eksik (Google › Ayarlar).',
                default => 'Ajans Google kullanıcısıyla bir kez izin verin; MCC altındaki bütün hesaplar görünür.',
            },
            ['link', route('operator.integrations.google', ['tab' => $connected ? 'configuration' : 'overview']), $connected ? 'Jetonu gir' : 'Google ile bağlan'])];
    }

    /** @return list<array<string, mixed>> */
    private function metaSteps(?CoreIntegration $meta): array
    {
        $connected = $meta !== null && $meta->isActive() && app(MetaCredentialResolver::class)->hasTenantAuthorization($meta);
        $selected = $connected && app(SelectMetaDiscoveryContextService::class)->hasSelection($meta);

        return [
            $this->step('connect', 'Meta bağlantısı', $connected, $connected ? 'Bağlı.' : 'Ajans Meta kullanıcısıyla bir kez izin verin.',
                ['link', route('operator.integrations.meta'), 'Meta ile bağlan']),
            $this->step('business', 'Business seçimi', $selected,
                $selected ? 'Seçili Business\'ların sahip olduğu ve yönettiği reklam hesapları listelenir.' : 'Müşterinin reklam hesaplarını gören Business\'ı seçin; seçince hesaplar hemen listelenir.',
                ['link', route('operator.integrations.meta', ['tab' => 'resources']), 'Business seç']),
        ];
    }

    /**
     * @param  array{0: string, 1: ?string, 2: ?string, 3?: string}|null  $action
     * @return array<string, mixed>
     */
    private function step(string $key, string $label, bool $done, string $detail, ?array $action, bool $optional = false): array
    {
        $normalized = null;
        if ($action !== null) {
            $normalized = $action[0] === 'link'
                ? ['kind' => 'link', 'url' => $action[1], 'label' => $action[2]]
                : ['kind' => 'call', 'method' => $action[1], 'arg' => $action[2], 'label' => $action[3] ?? ''];
        }

        return ['key' => $key, 'label' => $label, 'done' => $done, 'optional' => $optional, 'detail' => $detail, 'action' => $normalized, 'state' => null];
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @param  array{label: string, url: string}|null  $daily
     * @return array<string, mixed>
     */
    private function channel(string $key, string $label, array $steps, bool $unused, ?array $daily): array
    {
        $seenNext = false;
        foreach ($steps as $index => $step) {
            $steps[$index]['state'] = match (true) {
                $step['done'] => 'done',
                $step['optional'] => 'optional',
                ! $seenNext => 'next',
                default => 'waiting',
            };
            $seenNext = $seenNext || $steps[$index]['state'] === 'next';
        }
        $required = array_filter($steps, fn (array $s): bool => ! $s['optional']);
        $doneCount = count(array_filter($required, fn (array $s): bool => $s['done']));

        return [
            'key' => $key, 'label' => $label, 'steps' => $steps, 'unused' => $unused,
            'done' => $doneCount, 'total' => count($required), 'complete' => $doneCount === count($required),
            'next' => collect($steps)->firstWhere('state', 'next'),
            'daily' => $daily,
        ];
    }

    /** @param array<int, list<DataStatus>> $statuses */
    private function sourceDetail(array $statuses, ?DigitalAsset $site, string $capability): string
    {
        $status = $site !== null ? collect($statuses[(int) $site->id] ?? [])->firstWhere('capability', $capability) : null;

        return $status instanceof DataStatus ? 'Bağlı · '.$status->shortLabel() : 'Bağlı.';
    }

    private function lastCrawl(DigitalAsset $site): ?string
    {
        $run = CollectionRun::query()->where('digital_asset_id', $site->id)->latest('id')->limit(25)->get()
            ->first(fn (CollectionRun $run): bool => in_array('WEBSITE_DIRECT', (array) data_get($run->request_context, 'provider_sources', []), true));
        if ($run === null) {
            return null;
        }
        $status = $run->status;

        return $status instanceof \BackedEnum ? (string) $status->value : (string) $status;
    }
}
