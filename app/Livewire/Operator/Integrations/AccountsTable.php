<?php

namespace App\Livewire\Operator\Integrations;

use App\Enums\Collection\ActivityTier;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\ResourceActivity;
use App\Models\ResourceAutomation;
use App\Models\User;
use App\Services\Integrations\ResourceAutomationService;
use App\Services\Portfolio\PortfolioGroupCreator;
use App\Services\SeoTasks\SeoText;
use App\Support\Roles;
use App\Support\SchemaState;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Bağlantılar › Hesaplar (Aşama 6): every Google Ads, Meta and İşletme Profili account in one table — the brand it
 * serves (one select to bind an unbound account), its state (çalışıyor, erişim yok, harcamasız, kullanılmıyor, bağlı
 * değil), its last data and one "Şimdi güncelle". Marka › Dijital varlıklar shows the same component filtered to the
 * brand's accounts.
 */
final class AccountsTable extends Component
{
    /** Resource type => label. */
    public const array TYPES = ['google_ads' => 'Google Ads', 'meta_ads' => 'Meta', 'google_business_profile' => 'İşletme Profili'];

    /** State => [label, chip], in list order: what needs a hand first. */
    public const array STATES = [
        'no_access' => ['Erişim yok', 'chip-down'],
        'unbound' => ['Bağlı değil', 'chip-accent'],
        'ok' => ['Çalışıyor', 'chip-up'],
        'no_spend' => ['Harcamasız', 'chip-flat'],
        'not_served' => ['Kullanılmıyor', 'chip-flat'],
    ];

    /** 0 = every brand (Entegrasyonlar › Hesaplar); a brand id = that brand's accounts only. */
    #[Locked]
    public int $brandId = 0;

    #[Url(as: 'tur')]
    public string $type = '';

    #[Url(as: 'durum')]
    public string $state = '';

    #[Url(as: 'ara')]
    public string $search = '';

    /** @var array<int|string, string> resource id => brand id chosen in the row's select */
    public array $bindTo = [];

    public string $message = '';

    public function bind(int $resourceId, PortfolioGroupCreator $creator): void
    {
        $actor = $this->admin();
        $brand = Brand::query()->find((int) ($this->bindTo[$resourceId] ?? 0));
        if ($brand === null) {
            $this->message = 'Önce marka seçin.';

            return;
        }
        $resource = CoreExternalResource::query()->whereIn('resource_type', array_keys(self::TYPES))->findOrFail($resourceId);
        if ($this->boundAsset($resourceId) !== null) {
            $this->message = 'Bu hesap zaten bir markaya bağlı.';

            return;
        }
        $site = $brand->digitalAssets()->where('type', 'website')->orderBy('id')->first();
        try {
            $outcome = $creator->create(['brand_id' => $brand->id, 'website_url' => (string) ($site?->primary_url ?: ($site?->domain ? 'https://'.$site->domain.'/' : ''))], [(int) $resource->id], $actor);
            $failed = collect($outcome['results'])->where('ok', false)->pluck('message')->first();
            $this->message = $failed !== null ? 'Bağlanamadı: '.$failed : '"'.$this->name($resource).'" '.$brand->name.' markasına bağlandı; verisi birkaç dakika içinde gelir.';
        } catch (ValidationException $exception) {
            $this->message = (string) collect($exception->errors())->flatten()->first();
        } catch (Throwable $exception) {
            report($exception);
            $this->message = 'Bağlanamadı.';
        }
        unset($this->bindTo[$resourceId]);
    }

    /** "Kullanılmıyor": a business the agency does not serve; never suggested again, never disconnected. */
    public function notServed(int $resourceId): void
    {
        $this->admin();
        $resource = CoreExternalResource::query()->whereIn('resource_type', array_keys(self::TYPES))->findOrFail($resourceId);
        $meta = is_array($resource->metadata) ? $resource->metadata : [];
        if (empty($meta['not_served_at'])) {
            $meta['not_served_at'] = now()->toIso8601String();
            $this->message = '"'.$this->name($resource).'" kullanılmıyor olarak işaretlendi; hiçbir markaya önerilmez.';
        } else {
            unset($meta['not_served_at']);
            $this->message = '"'.$this->name($resource).'" yeniden önerilebilir.';
        }
        $resource->forceFill(['metadata' => $meta])->save();
    }

    public function refreshNow(int $resourceId, ResourceAutomationService $automation): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->is_active, 403);
        $automation->discover();
        $id = ResourceAutomation::query()->where('external_resource_id', $resourceId)->value('id');
        if ($id === null) {
            $this->message = 'Bu hesabın veri çekimi henüz kurulmadı.';

            return;
        }
        $automation->runNow((int) $id, $actor);
        $this->message = 'Veri çekimi öne alındı; birkaç dakika içinde başlar.';
    }

    public function render(): View
    {
        $rows = $this->rows();
        $counts = ['' => $rows->count()] + array_map(fn (): int => 0, self::STATES);
        foreach ($rows as $row) {
            $counts[$row['state']]++;
        }
        $needle = SeoText::fold($this->search);
        $visible = $rows->filter(fn (array $r): bool => ($this->type === '' || $r['type'] === $this->type)
            && ($this->state === '' || $r['state'] === $this->state)
            && ($needle === '' || str_contains(SeoText::fold($r['name'].' '.$r['external_id'].' '.$r['brand']), $needle)))->values();

        return view('livewire.operator.integrations.accounts-table', [
            'rows' => $visible,
            'counts' => $counts,
            'brands' => $this->brandId === 0 ? Brand::query()->orderBy('name')->pluck('name', 'id')->all() : [],
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
        ]);
    }

    /**
     * @return Collection<int, array{id: int, type: string, type_label: string, name: string, external_id: string, brand_id: ?int, brand: string, asset_id: ?int, state: string, last: ?CarbonInterface, error: ?string}>
     */
    private function rows(): Collection
    {
        $bindings = CoreAssetBinding::query()->where('status', CoreAssetBinding::STATUS_ACTIVE)->with('digitalAsset.brand')
            ->whereHas('digitalAsset')->get()->keyBy('external_resource_id');
        $resources = CoreExternalResource::query()->with('integration')->whereIn('resource_type', array_keys(self::TYPES))->orderBy('display_name')->orderBy('id')->get()
            ->reject(fn (CoreExternalResource $r): bool => (bool) data_get($r->metadata, 'is_manager', false));
        if ($this->brandId !== 0) {
            $resources = $resources->filter(fn (CoreExternalResource $r): bool => (int) ($bindings->get($r->id)?->digitalAsset?->brand_id ?? 0) === $this->brandId);
        }
        $ids = $resources->modelKeys();
        $automations = ResourceAutomation::query()->whereIn('external_resource_id', $ids)->get(['external_resource_id', 'last_collection_success_at', 'collection_error'])->keyBy('external_resource_id');
        $dormant = SchemaState::hasTable('resource_activity')
            ? ResourceActivity::query()->whereIn('external_resource_id', $ids)->get()->filter(fn (ResourceActivity $a): bool => $a->effectiveTier() === ActivityTier::Dormant)->keyBy('external_resource_id')
            : collect();

        return $resources->map(function (CoreExternalResource $r) use ($bindings, $automations, $dormant): array {
            $asset = $bindings->get($r->id)?->digitalAsset;
            $automation = $automations->get($r->id);
            $state = match (true) {
                ! empty(data_get($r->metadata, 'not_served_at')) => 'not_served',
                $r->status !== CoreExternalResource::STATUS_AVAILABLE || ($r->integration !== null && $r->integration->status !== CoreIntegration::STATUS_ACTIVE) => 'no_access',
                $asset === null => 'unbound',
                $r->resource_type !== 'google_business_profile' && $dormant->has($r->id) => 'no_spend',
                default => 'ok',
            };

            return [
                'id' => (int) $r->id,
                'type' => (string) $r->resource_type,
                'type_label' => self::TYPES[$r->resource_type],
                'name' => $this->name($r),
                'external_id' => (string) $r->external_id,
                'brand_id' => $asset?->brand_id !== null ? (int) $asset->brand_id : null,
                'brand' => (string) ($asset?->brand?->name ?? ''),
                'asset_id' => $asset !== null ? (int) $asset->id : null,
                'state' => $state,
                'last' => $automation?->last_collection_success_at,
                'error' => $automation?->collection_error ? (string) $automation->collection_error : null,
            ];
        })->sortBy([fn (array $a, array $b): int => array_search($a['state'], array_keys(self::STATES), true) <=> array_search($b['state'], array_keys(self::STATES), true)])
            ->values();
    }

    private function boundAsset(int $resourceId): ?int
    {
        return CoreAssetBinding::query()->where('status', CoreAssetBinding::STATUS_ACTIVE)->where('external_resource_id', $resourceId)->value('digital_asset_id');
    }

    private function name(CoreExternalResource $resource): string
    {
        return trim((string) ($resource->display_name ?: $resource->external_id));
    }

    private function admin(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->is_active && $actor->hasRole(Roles::ADMIN), 403);

        return $actor;
    }
}
