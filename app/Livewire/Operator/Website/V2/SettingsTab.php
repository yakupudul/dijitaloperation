<?php

namespace App\Livewire\Operator\Website\V2;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\Page;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Ayarlar: sitemap URL override, weekly content capacity (per brand, default 4) and the operator's category corrections
 * (locked categories; "Kilidi kaldır" gives the page back to the rules / AI).
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

    public function render(): View
    {
        return view('livewire.operator.website.v2.settings-tab', [
            'corrections' => Page::query()->where('website_asset_id', $this->assetId)->where('category_locked', true)->orderBy('path')->limit(200)->get(['id', 'url', 'path', 'category']),
        ]);
    }
}
