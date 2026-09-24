<?php

namespace App\Livewire\Operator\Market;

use App\Enums\CustomerStatus;
use App\Models\Brand;
use App\Models\Intel\BrandIntelSetting;
use App\Models\SearchDemandCompetitor;
use App\Services\GoogleAds\AuctionInsightsImporter;
use App\Services\Intel\CompetitorSiteWatch;
use App\Services\Intel\DataForSeoTaskQueue;
use App\Services\Intel\ReviewIntelService;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Pazar › Rakip izleme (Faz 8d/8e): Google reviews of the brand vs nearby competitors, weekly competitor site
 * changes, Meta Ad Library links and uploaded Google Ads auction insights (Faz 14g). Internal agency view; paid review reads are admin-only.
 */
#[Layout('operator.layouts.app')]
#[Title('Rakip izleme')]
final class CompetitorWatchPage extends Component
{
    #[Url]
    public ?int $brand = null;

    #[Url]
    public string $tab = 'reviews';

    #[Url]
    public ?int $profile = null;

    public string $manualTitle = '';

    public string $manualCid = '';

    /** @var array<string, string> competitor id (or "brand") => Facebook page id */
    public array $pageIds = [];

    public string $message = '';

    public string $error = '';

    public function updatedBrand(): void
    {
        $this->profile = null;
        $this->pageIds = [];
    }

    public function refreshReviews(ReviewIntelService $reviews): void
    {
        $this->admin();
        try {
            $count = $reviews->refresh($this->selectedBrand() ?? abort(404));
            $this->error = '';
            $this->message = $count.' profil için yorum okuma kuyruğa alındı; sonuçlar birkaç dakikada gelir.';
        } catch (ValidationException $exception) {
            $this->message = '';
            $this->error = (string) collect($exception->errors())->flatten()->first();
        }
    }

    public function toggleReviews(): void
    {
        $this->admin();
        $settings = BrandIntelSetting::for($this->selectedBrand() ?? abort(404));
        $settings->forceFill(['reviews_enabled' => ! $settings->reviews_enabled, 'updated_by' => auth()->id()])->save();
        $this->message = $settings->reviews_enabled ? 'Otomatik yorum okuma açıldı ('.config('moxdop-intel.reviews.every_days').' günde bir).' : 'Otomatik yorum okuma kapatıldı.';
    }

    public function addProfile(ReviewIntelService $reviews): void
    {
        $this->admin();
        try {
            $reviews->addManual($this->selectedBrand() ?? abort(404), $this->manualTitle, trim($this->manualCid));
            $this->manualTitle = $this->manualCid = '';
            $this->error = '';
            $this->message = 'Rakip profili eklendi.';
        } catch (ValidationException $exception) {
            $this->error = (string) collect($exception->errors())->flatten()->first();
        }
    }

    public function toggleProfile(int $id): void
    {
        $this->admin();
        $row = DB::table('review_profiles')->where('id', $id)->where('brand_id', $this->brand)->where('is_own', false)->first() ?? abort(404);
        DB::table('review_profiles')->where('id', $row->id)->update(['active' => ! $row->active, 'updated_at' => now()]);
    }

    public function watchNow(CompetitorSiteWatch $watch): void
    {
        $this->admin();
        $stats = $watch->watchBrand($this->selectedBrand() ?? abort(404), true);
        $this->message = sprintf('%d rakip sitesi okundu, %d tanesinde değişiklik var.', $stats['watched'], $stats['changed']);
    }

    public function savePageIds(): void
    {
        $brand = $this->selectedBrand() ?? abort(404);
        foreach ($this->pageIds as $key => $value) {
            $value = trim((string) $value);
            if ($value !== '' && preg_match('/^\d{5,25}$/', $value) !== 1) {
                $this->error = 'Sayfa kimliği yalnız rakamlardan oluşur (Reklam Kütüphanesi adresindeki view_all_page_id).';

                return;
            }
            if ($key === 'brand') {
                BrandIntelSetting::for($brand)->forceFill(['facebook_page_id' => $value ?: null])->save();
            } else {
                SearchDemandCompetitor::query()->where('brand_id', $brand->id)->where('id', (int) $key)->update(['facebook_page_id' => $value ?: null]);
            }
        }
        $this->error = '';
        $this->message = 'Sayfa kimlikleri kaydedildi.';
    }

    public function render(ReviewIntelService $reviews, DataForSeoTaskQueue $queue): View
    {
        $brands = Brand::query()->whereHas('customer', fn ($q) => $q->where('status', CustomerStatus::Active->value))->orderBy('name')->get(['id', 'name']);
        $this->brand ??= $brands->first()?->id;
        $brand = $this->selectedBrand();
        $settings = $brand !== null ? BrandIntelSetting::for($brand) : null;
        $comparison = $brand !== null ? $reviews->comparison($brand) : [];
        $this->profile ??= collect($comparison)->firstWhere('is_own', true)['id'] ?? ($comparison[0]['id'] ?? null);
        $competitors = $brand !== null ? SearchDemandCompetitor::query()->where('brand_id', $brand->id)->where('status', 'approved')->orderBy('display_name')->get() : collect();
        if ($brand !== null && $this->pageIds === []) {
            $this->pageIds = ['brand' => (string) $settings?->facebook_page_id] + $competitors->mapWithKeys(fn ($c) => [(string) $c->id => (string) $c->facebook_page_id])->all();
        }
        $sites = $brand !== null ? DB::table('competitor_site_snapshots')->where('brand_id', $brand->id)->orderByDesc('observed_on')->orderByDesc('id')->limit(200)->get(['id', 'domain', 'observed_on', 'status', 'title', 'h1', 'sitemap_urls_count', 'new_urls', 'removed_urls_count', 'changes'])->groupBy('domain') : collect();

        return view('livewire.operator.market.competitor-watch', [
            'brands' => $brands,
            'settings' => $settings,
            'comparison' => $comparison,
            'themes' => $this->profile !== null && collect($comparison)->contains('id', $this->profile) ? $reviews->negativeThemes($this->profile) : [],
            'selectedProfile' => collect($comparison)->firstWhere('id', $this->profile),
            'recentNegative' => $this->profile !== null ? DB::table('review_items')->where('review_profile_id', $this->profile)->where('rating', '<=', (int) config('moxdop-intel.reviews.negative_max_rating', 2))->whereNotNull('text')->orderByDesc('published_at')->limit(5)->get() : collect(),
            'estimate' => $brand !== null ? $reviews->estimate($brand) : 0.0,
            'spent' => $brand !== null ? $queue->spentThisMonth((int) $brand->id) : 0.0,
            'sites' => $sites,
            'competitors' => $competitors,
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
            'auction' => $brand !== null && $this->tab === 'auction' ? app(AuctionInsightsImporter::class)->forBrand($brand) : [],
        ]);
    }

    private function selectedBrand(): ?Brand
    {
        return $this->brand !== null ? Brand::query()->find($this->brand) : null;
    }

    private function admin(): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
    }
}
