<?php

namespace App\Services\CommandCenter;

use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\ResourceAutomation;
use Illuminate\Support\Collection;

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
            ->whereIn('id', CoreAssetBinding::query()->where('status', CoreAssetBinding::STATUS_ACTIVE)->select('external_resource_id'))->limit(50)->get();
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

        $noConsole = Brand::query()->whereHas('customer', fn ($q) => $q->where('status', 'active'))
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
