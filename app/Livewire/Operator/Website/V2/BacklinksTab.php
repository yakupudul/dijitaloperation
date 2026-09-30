<?php

namespace App\Livewire\Operator\Website\V2;

use App\Jobs\Site\ProposeBacklinkSourcesJob;
use App\Jobs\Site\VerifyBacklinkSourceJob;
use App\Livewire\Operator\Website\V2\Concerns\WebsiteTab;
use App\Models\Backlink;
use App\Models\BacklinkSource;
use App\Models\Brand;
use App\Services\Site\Backlinks\BacklinkImporter;
use App\Services\Site\Backlinks\BacklinkSourceProposer;
use App\Services\Site\SiteDomains;
use App\Support\ServiceScope;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Throwable;

/**
 * SEO Yapılacaklar › Backlinkler: "Bağlantı verenler" (Search Console Links export import, manual) and "Potansiyel
 * kaynaklar" (AI by sector + areas, manual) with five statuses: henüz tespit edilmedi · başvuru / iletişim yapıldı
 * (operator) · kullanıcı eklediğini bildirdi (operator enters the link URL) · sayfada doğrulandı · daha sonra kaldırıldı
 * (the last two by the verifier).
 */
final class BacklinksTab extends Component
{
    use WebsiteTab;
    use WithFileUploads;
    use WithPagination;

    /** @var TemporaryUploadedFile|null */
    public $export = null;

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
        $this->validate(['export' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xlsx']], [], ['export' => 'dosya']);
        try {
            $stats = $importer->import($brand, $this->export->getRealPath(), (string) $this->export->getClientOriginalName());
        } catch (Throwable $error) {
            throw ValidationException::withMessages(['export' => $error->getMessage()]);
        }
        $this->reset('export');
        $this->message = $stats['imported'].' yeni · '.$stats['updated'].' güncellendi · '.$stats['skipped'].' atlandı';
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
        $this->actor();
        BacklinkSource::query()->where('brand_id', $this->brand()->id)->whereKey($id)->delete();
    }

    public function render(): View
    {
        $site = $this->site();
        $brand = $site->brand;
        $proposal = $brand !== null ? Cache::get(BacklinkSourceProposer::statusKey((int) $brand->id)) : null;

        return view('livewire.operator.website.v2.backlinks-tab', [
            'site' => $site,
            'operational' => $this->operational($site),
            'backlinks' => Backlink::query()->where('brand_id', $brand?->id ?? 0)->orderByDesc('first_seen')->orderBy('source_domain')->paginate(50, pageName: 'links'),
            'domains' => Backlink::query()->where('brand_id', $brand?->id ?? 0)->distinct()->count('source_domain'),
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
