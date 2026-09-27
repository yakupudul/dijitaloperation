<?php

namespace App\Services\CommandCenter;

use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\ResourceAutomation;
use App\Services\Integrations\BrandAccountCandidates;
use App\Support\ServiceScope;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Kurulum eksikleri in the command center: accounts to reconnect, accounts that lost access, accounts no brand uses,
 * and brands whose website has no Search Console.
 */
final class CoverageSource implements CommandCenterSource
{
    public function items(): Collection
    {
        $out = collect();

        $reconnect = ResourceAutomation::query()->with('resource')->where('collection_enabled', true)
            ->where('collection_status', 'attention')->where('collection_error', 'reconnect')->get()
            ->filter(fn (ResourceAutomation $a): bool => $a->resource !== null)->groupBy(fn (ResourceAutomation $a): string => (string) $a->resource->provider);
        foreach ($reconnect as $provider => $rows) {
            $integration = (int) $rows->first()->resource->integration_id;
            $out->push(CommandCenter::item('coverage', 'reconnect-'.$provider, 'critical', ($provider === 'meta' ? 'Meta' : 'Google').' bağlantısı yenilenmeli ('.$rows->count().' hesabın verisi çekilemiyor)', [
                'detail' => 'Tek tıkla izin ekranına gidin; yeniden bağlanınca hesaplar kendiliğinden devam eder.',
                'rule' => 'reconnect',
                'channel' => 'Entegrasyon',
                'url' => route($provider === 'meta' ? 'integrations.meta.authorize' : 'integrations.google.authorize', ['integration' => $integration]),
            ]));
        }

        $lost = CoreExternalResource::query()->where('status', CoreExternalResource::STATUS_UNAVAILABLE)
            ->whereIn('id', CoreAssetBinding::query()->where('status', CoreAssetBinding::STATUS_ACTIVE)
                ->whereIn('digital_asset_id', app(ServiceScope::class)->assetIdQuery())->select('external_resource_id'))->limit(50)->get();
        foreach ($lost as $resource) {
            $out->push(CommandCenter::item('coverage', 'lost-'.$resource->id, 'critical', 'Erişim kaybedildi: '.($resource->display_name ?: $resource->external_id), [
                'detail' => 'Bu hesap artık bağlı Google/Meta kullanıcısına görünmüyor; müşteriden erişimi yeniden isteyin ya da bağlantıyı kaldırın.',
                'rule' => 'lost',
                'channel' => 'Entegrasyon',
                'url' => route($resource->provider === 'meta' ? 'operator.integrations.meta' : 'operator.integrations.google', ['tab' => 'resources']),
            ]));
        }

        $unbound = ResourceAutomation::query()->with('resource')->where('collection_status', 'attention')->where('collection_error', 'unbound')->get()
            ->filter(fn (ResourceAutomation $a): bool => $a->resource !== null && $a->resource->status !== CoreExternalResource::STATUS_UNAVAILABLE);
        if ($unbound->isNotEmpty()) {
            $out->push(CommandCenter::item('coverage', 'unbound', 'medium', $unbound->count().' hesap hiçbir markaya bağlı değil', [
                'detail' => $unbound->take(6)->map(fn (ResourceAutomation $a): string => (string) ($a->resource->display_name ?: $a->resource->external_id))->implode(', ').($unbound->count() > 6 ? '…' : '').' — veri çekilmiyor. Keşfet ve Grupla ile markalara dağıtın.',
                'channel' => 'Entegrasyon',
                'rule' => 'unbound',
                'url' => route('operator.portfolio.discover'),
            ]));
        }

        // Accounts that most likely belong to a brand (its own MCC / Business, or the brand's name) but are bound to no
        // asset: they are not collected and not in the brand's totals. One item per brand, bound with "Hesap ekle".
        $brands = Brand::query()->operational()->orderBy('name')->get(['id', 'name', 'customer_id']);
        try {
            $strong = app(BrandAccountCandidates::class)->strongForBrands($brands);
        } catch (Throwable $error) {
            report($error);
            $strong = [];
        }
        foreach ($strong as $brandId => $candidates) {
            $brand = $brands->firstWhere('id', $brandId);
            $count = count($candidates);
            $out->push(CommandCenter::item('coverage', 'brand-unbound-'.$brandId, 'medium', $brand->name.': '.$count.' reklam hesabı bağlanmamış', [
                'detail' => collect($candidates)->take(4)->map(fn (array $c): string => $c['type_label'].' · '.$c['name'].($c['container_label'] ? ' ('.$c['container_label'].')' : ''))->implode(', ')
                    .($count > 4 ? '…' : '').' — veri çekilmiyor, marka toplamlarına girmiyor. Marka sayfasında "Hesap ekle" ile bağlayın.',
                'channel' => 'Entegrasyon',
                'rule' => 'brand-unbound',
                'brand_id' => (int) $brandId,
                'brand' => $brand->name,
                'url' => route('operator.brand', ['brand' => $brandId, 'tab' => 'assets']).'#hesap-ekle',
            ]));
        }

        $noConsole = Brand::query()->operational()
            ->whereHas('digitalAssets', fn ($q) => $q->where('type', 'website')->where('status', 'active'))
            ->whereDoesntHave('digitalAssets.assetBindings', fn ($q) => $q->where('capability', 'search_console')->where('status', CoreAssetBinding::STATUS_ACTIVE))
            ->orderBy('name')->pluck('name');
        if ($noConsole->isNotEmpty()) {
            $out->push(CommandCenter::item('coverage', 'no-search-console', 'medium', $noConsole->count().' markanın sitesinde Search Console bağlı değil', [
                'detail' => $noConsole->take(8)->implode(', ').($noConsole->count() > 8 ? '…' : '').' — sorgu ve SEO önerileri bu veri olmadan eksik kalır.',
                'channel' => 'Kurulum',
                'rule' => 'no-search-console',
                'url' => route('operator.portfolio.health'),
            ]));
        }

        return $out;
    }
}
