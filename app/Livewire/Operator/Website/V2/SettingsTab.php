<?php

namespace App\Livewire\Operator\Website\V2;

use App\Models\Brand;
use App\Models\Collection\CollectionRun;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Services\SeoTasks\SeoPlanInputCollector;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Ayarlar: sitemap URL override, weekly content capacity (per brand, default 4) and the operator's category corrections
 * (locked categories; "Kilidi kaldır" gives the page back to the rules / AI), plus one line on the last website data
 * collection with a link to the existing collection screen ("Şimdi güncelle").
 */
final class SettingsTab extends Component
{
    #[Locked]
    public int $assetId = 0;

    public string $sitemapUrl = '';

    public int $capacity = 4;

    public string $message = '';

    public function mount(int $assetId): void
    {
        $this->assetId = $assetId;
        $site = DigitalAsset::query()->with('brand')->findOrFail($assetId);
        $this->sitemapUrl = (string) ($site->sitemap_url ?? '');
        $this->capacity = (int) ($site->brand?->weekly_content_capacity ?? 4);
    }

    public function saveSitemap(): void
    {
        $this->validate(['sitemapUrl' => ['nullable', 'url:http,https', 'max:2000']], [], ['sitemapUrl' => 'Sitemap URL']);
        DigitalAsset::query()->whereKey($this->assetId)->update(['sitemap_url' => trim($this->sitemapUrl) !== '' ? trim($this->sitemapUrl) : null]);
        $this->message = 'Sitemap adresi kaydedildi.';
    }

    public function saveCapacity(): void
    {
        $this->validate(['capacity' => ['required', 'integer', 'min:1', 'max:20']], [], ['capacity' => 'Haftalık kapasite']);
        $brandId = DigitalAsset::query()->whereKey($this->assetId)->value('brand_id');
        if ($brandId !== null) {
            Brand::query()->whereKey($brandId)->update(['weekly_content_capacity' => $this->capacity]);
        }
        $this->message = 'Haftalık kapasite kaydedildi.';
    }

    public function unlockCategory(int $pageId): void
    {
        Page::query()->where('website_asset_id', $this->assetId)->whereKey($pageId)->update(['category_locked' => false, 'category_source' => null]);
        $this->message = 'Kilit kaldırıldı; sonraki sınıflandırmada kurallar / AI karar verir.';
    }

    private const array RUN_LABELS = [
        'completed' => 'başarılı', 'partial' => 'kısmen tamamlandı', 'failed' => 'başarısız', 'cancelled' => 'iptal edildi',
        'cancellation_requested' => 'iptal ediliyor', 'queued' => 'kuyrukta', 'running' => 'sürüyor', 'retrying' => 'yeniden deneniyor',
        'skipped' => 'atlandı', 'not_eligible' => 'uygun değil',
    ];

    public function render(): View
    {
        $run = CollectionRun::query()->where('digital_asset_id', $this->assetId)->latest('id')->first(['id', 'status', 'updated_at']);
        $status = $run === null ? '' : (string) ($run->status instanceof \BackedEnum ? $run->status->value : $run->status);

        return view('livewire.operator.website.v2.settings-tab', [
            'collection' => $run === null ? null : ['status' => self::RUN_LABELS[$status] ?? $status, 'at' => $run->updated_at],
            'gscSitemaps' => app(SeoPlanInputCollector::class)->sitemaps(DigitalAsset::query()->findOrFail($this->assetId)),
            'corrections' => Page::query()->where('website_asset_id', $this->assetId)->where('category_locked', true)->orderBy('path')->limit(200)->get(['id', 'url', 'path', 'category']),
        ]);
    }
}
