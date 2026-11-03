<?php

namespace App\Livewire\Operator\Website\V2;

use App\Jobs\Site\ProposeBacklinkSourcesJob;
use App\Jobs\Site\VerifyBacklinkSourceJob;
use App\Livewire\Concerns\AdminOnly;
use App\Livewire\Operator\Website\V2\Concerns\WebsiteTab;
use App\Models\Backlink;
use App\Models\BacklinkSource;
use App\Models\Brand;
use App\Services\Operator\ExternalLinks;
use App\Services\Site\Backlinks\BacklinkImporter;
use App\Services\Site\Backlinks\BacklinkSourceProposer;
use App\Services\Site\SiteDomains;
use App\Support\ServiceScope;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Throwable;

/**
 * SEO Yapılacaklar › Backlinkler: "Bağlantı verenler" (Bing Webmaster weekly, Search Console Links exports, manual) and "Potansiyel
 * kaynaklar" (AI by sector + areas, manual) with five statuses: henüz tespit edilmedi · başvuru / iletişim yapıldı
 * (operator) · kullanıcı eklediğini bildirdi (operator enters the link URL) · sayfada doğrulandı · daha sonra kaldırıldı
 * (the last two by the verifier).
 */
final class BacklinksTab extends Component
{
    use AdminOnly;
    use WebsiteTab;
    use WithFileUploads;
    use WithPagination;

    /** @var list<TemporaryUploadedFile> Search Console Links exports (several at once: linking sites, latest links …) */
    public array $exports = [];

    public string $linkUrl = '';

    public string $linkTarget = '';

    public string $sourceName = '';

    public string $sourceUrl = '';

    /** @var array<int|string, string> source id => entered link URL */
    public array $given = [];

    public function importExport(BacklinkImporter $importer): void
    {
        $this->actor();
        $brand = $this->brand();
        $this->validate(['exports' => ['required', 'array', 'min:1', 'max:10'], 'exports.*' => ['file', 'max:10240', 'mimes:csv,txt,xlsx']], [], ['exports' => 'dosya', 'exports.*' => 'dosya']);
        $total = ['imported' => 0, 'updated' => 0, 'skipped' => 0];
        $errors = [];
        foreach ($this->exports as $file) {
            $name = (string) $file->getClientOriginalName();
            try {
                $stats = $importer->import($brand, $file->getRealPath(), $name);
            } catch (Throwable $error) {
                $errors[] = $name.': '.$error->getMessage();

                continue;
            }
            foreach ($total as $key => $count) {
                $total[$key] = $count + $stats[$key];
            }
        }
        $this->reset('exports');
        if ($errors !== [] && $total === ['imported' => 0, 'updated' => 0, 'skipped' => 0]) {
            throw ValidationException::withMessages(['exports' => implode(' · ', $errors)]);
        }
        $this->message = $total['imported'].' yeni · '.$total['updated'].' güncellendi · '.$total['skipped'].' atlandı'.($errors !== [] ? ' · okunamayan: '.implode(' · ', $errors) : '');
    }

    public function addBacklink(): void
    {
        $this->actor();
        $brand = $this->brand();
        $this->validate(['linkUrl' => ['required', 'url:http,https', 'max:2000'], 'linkTarget' => ['nullable', 'url:http,https', 'max:2000']], [], ['linkUrl' => 'bağlantı veren sayfa', 'linkTarget' => 'hedef sayfa']);
        $domain = SiteDomains::host($this->linkUrl) ?? throw ValidationException::withMessages(['linkUrl' => 'Geçersiz adres.']);
        Backlink::query()->firstOrCreate(
            ['brand_id' => $brand->id, 'link_hash' => hash('sha256', $this->linkUrl.'|'.$this->linkTarget)],
            ['source_url' => $this->linkUrl, 'source_domain' => $domain, 'target_url' => $this->linkTarget !== '' ? $this->linkTarget : null, 'first_seen' => now()->toDateString(), 'source' => 'manual', 'status' => 'aktif'],
        );
        $this->reset(['linkUrl', 'linkTarget']);
    }

    public function removeBacklink(int $id): void
    {
        $this->adminOnly();
        $this->actor();
        Backlink::query()->where('brand_id', $this->brand()->id)->whereKey($id)->delete();
    }

    public function proposeSources(): void
    {
        $this->actor();
        $brand = $this->brand();
        if (! $brand->isOperational()) {
            $this->message = ServiceScope::NOT_SERVED;

            return;
        }
        BacklinkSourceProposer::markRunning((int) $brand->id);
        ProposeBacklinkSourcesJob::dispatch((int) $brand->id);
        $this->message = 'Kaynaklar hazırlanıyor.';
    }

    public function addSource(): void
    {
        $this->actor();
        $brand = $this->brand();
        $this->validate(['sourceName' => ['required', 'string', 'max:200'], 'sourceUrl' => ['required', 'url:http,https', 'max:2000']], [], ['sourceName' => 'ad', 'sourceUrl' => 'adres']);
        $domain = SiteDomains::host($this->sourceUrl) ?? throw ValidationException::withMessages(['sourceUrl' => 'Geçersiz adres.']);
        if (BacklinkSource::query()->where('brand_id', $brand->id)->where('domain', $domain)->exists()) {
            throw ValidationException::withMessages(['sourceUrl' => 'Bu site listede var.']);
        }
        BacklinkSource::query()->create(['brand_id' => $brand->id, 'name' => $this->sourceName, 'url' => $this->sourceUrl, 'domain' => $domain,
            'kind' => 'diger', 'fee' => 'teyit', 'origin' => 'manual', 'status' => BacklinkSource::NONE]);
        $this->reset(['sourceName', 'sourceUrl']);
    }

    public function markApplied(int $id): void
    {
        $this->actor();
        BacklinkSource::query()->where('brand_id', $this->brand()->id)->whereKey($id)->whereIn('status', [BacklinkSource::NONE, BacklinkSource::REMOVED])
            ->update(['status' => BacklinkSource::APPLIED, 'note' => now()->format('d.m.Y'), 'updated_at' => now()]);
    }

    public function markGiven(int $id): void
    {
        $this->actor();
        $source = BacklinkSource::query()->where('brand_id', $this->brand()->id)->findOrFail($id);
        $url = trim((string) ($this->given[$id] ?? ''));
        $this->validate(["given.{$id}" => ['required', 'url:http,https', 'max:2000']], [], ["given.{$id}" => 'bağlantı adresi']);
        $source->forceFill(['status' => BacklinkSource::GIVEN, 'link_url' => $url, 'note' => null])->save();
        unset($this->given[$id]);
        VerifyBacklinkSourceJob::dispatch((int) $source->id);
    }

    public function markNone(int $id): void
    {
        $this->actor();
        BacklinkSource::query()->where('brand_id', $this->brand()->id)->whereKey($id)
            ->update(['status' => BacklinkSource::NONE, 'link_url' => null, 'verified_at' => null, 'note' => null, 'updated_at' => now()]);
    }

    public function removeSource(int $id): void
    {
        $this->adminOnly();
        $this->actor();
        BacklinkSource::query()->where('brand_id', $this->brand()->id)->whereKey($id)->delete();
    }

    public function render(ExternalLinks $links): View
    {
        $site = $this->site();
        $brand = $site->brand;
        $property = $links->searchConsolePropertyFor((int) $site->id);
        $bing = DB::table('bing_sites')->where('digital_asset_id', $site->id)->first(['verified', 'links_collected_at', 'links_error']);
        $proposal = $brand !== null ? Cache::get(BacklinkSourceProposer::statusKey((int) $brand->id)) : null;

        return view('livewire.operator.website.v2.backlinks-tab', [
            'site' => $site,
            'operational' => $this->operational($site),
            'backlinks' => Backlink::query()->where('brand_id', $brand?->id ?? 0)->orderByRaw("CASE WHEN status = 'aktif' THEN 0 ELSE 1 END")
                ->orderByDesc('first_seen')->orderBy('source_domain')->paginate(50, pageName: 'links'),
            'domains' => Backlink::query()->where('brand_id', $brand?->id ?? 0)->where('status', 'aktif')->distinct()->count('source_domain'),
            'recent' => [
                'new' => Backlink::query()->where('brand_id', $brand?->id ?? 0)->where('first_seen', '>=', now()->subDays(30)->toDateString())->distinct()->count('source_domain'),
                'lost' => Backlink::query()->where('brand_id', $brand?->id ?? 0)->where('status', 'kaldirildi')->where('lost_at', '>=', now()->subDays(30)->toDateString())->count(),
            ],
            'gscLinks' => $property !== null ? $links->searchConsoleLinks($property) : null,
            'bing' => $bing,
            'sources' => BacklinkSource::query()->where('brand_id', $brand?->id ?? 0)
                ->orderByRaw("CASE status WHEN 'dogrulandi' THEN 0 WHEN 'verildi' THEN 1 WHEN 'basvuru' THEN 2 WHEN 'kaldirildi' THEN 3 ELSE 4 END")->orderBy('name')->paginate(50, pageName: 'sources'),
            'proposal' => $proposal,
            'polling' => ($proposal['status'] ?? null) === 'running',
        ]);
    }

    private function brand(): Brand
    {
        $brand = $this->site()->brand;
        if ($brand === null) {
            throw ValidationException::withMessages(['brand' => 'Site bir markaya bağlı değil.']);
        }

        return $brand;
    }
}
