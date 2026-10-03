<?php

namespace App\Livewire\Operator\Website\V2;

use App\Jobs\Site\PullClarityJob;
use App\Models\Brand;
use App\Models\ClarityProject;
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
 * collection with a link to the existing collection screen ("Şimdi güncelle"), and the site's Microsoft Clarity project.
 */
final class SettingsTab extends Component
{
    #[Locked]
    public int $assetId = 0;

    public string $sitemapUrl = '';

    public int $capacity = 4;

    public string $message = '';

    public string $clarityProjectId = '';

    /** Write-only: a saved token is never sent back to the browser. */
    public string $clarityToken = '';

    public function mount(int $assetId): void
    {
        $this->assetId = $assetId;
        $site = DigitalAsset::query()->with('brand')->findOrFail($assetId);
        $this->sitemapUrl = (string) ($site->sitemap_url ?? '');
        $this->capacity = (int) ($site->brand?->weekly_content_capacity ?? 4);
        $this->clarityProjectId = (string) (ClarityProject::query()->where('website_asset_id', $assetId)->value('project_id') ?? '');
    }

    /** Microsoft Clarity: the project id (for the dashboard link) and the Data Export API token of this site. */
    public function saveClarity(): void
    {
        $project = ClarityProject::query()->where('website_asset_id', $this->assetId)->first();
        $this->validate([
            'clarityProjectId' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]*$/'],
            'clarityToken' => [$project === null ? 'required' : 'nullable', 'string', 'min:20', 'max:4000'],
        ], [], ['clarityProjectId' => 'Clarity proje kimliği', 'clarityToken' => 'Clarity API token']);
        $fields = ['project_id' => trim($this->clarityProjectId) !== '' ? trim($this->clarityProjectId) : null, 'enabled' => true];
        if (trim($this->clarityToken) !== '') {
            $fields += ['api_token' => trim($this->clarityToken), 'last_status' => null, 'last_error' => null];
        }
        ClarityProject::query()->updateOrCreate(['website_asset_id' => $this->assetId], $fields);
        $this->clarityToken = '';
        $this->message = 'Clarity kaydedildi; her sabah 07:03\'te çekilir.';
    }

    /** "Şimdi çek": one pull now (the API allows 10 a day), at most once an hour. */
    public function pullClarity(): void
    {
        $project = ClarityProject::query()->where('website_asset_id', $this->assetId)->first();
        if ($project === null) {
            $this->message = 'Önce Clarity token\'ını kaydedin.';

            return;
        }
        if ($project->last_pulled_at !== null && $project->last_pulled_at->gt(now()->subHour())) {
            $this->message = 'Clarity son bir saat içinde çekildi; günlük istek sınırı için bekleyin.';

            return;
        }
        PullClarityJob::dispatch($this->assetId);
        $this->message = 'Clarity çekimi kuyruğa alındı.';
    }

    public function toggleClarity(): void
    {
        $project = ClarityProject::query()->where('website_asset_id', $this->assetId)->firstOrFail();
        $project->forceFill(['enabled' => ! $project->enabled])->save();
        $this->message = $project->enabled ? 'Clarity çekimi açıldı.' : 'Clarity çekimi durduruldu (veriler kalır).';
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
            'clarity' => ClarityProject::query()->where('website_asset_id', $this->assetId)->first(),
            'corrections' => Page::query()->where('website_asset_id', $this->assetId)->where('category_locked', true)->orderBy('path')->limit(200)->get(['id', 'url', 'path', 'category']),
        ]);
    }
}
