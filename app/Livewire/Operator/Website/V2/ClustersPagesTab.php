<?php

namespace App\Livewire\Operator\Website\V2;

use App\Models\BrandClusterPage;
use App\Models\BrandOffering;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Services\Queries\ClusterEditor;
use App\Services\Site\PageCategorizer;
use App\Services\Site\ServicePageMapper;
use App\Services\Site\SiteMetrics;
use App\Services\Site\SiteOperations;
use App\Services\Site\SiteScope;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * SEO Yapılacaklar › Kümeler & Sayfalar: per service the approved clusters with state, target URL, main / target query
 * and 28-day clicks / position (operator can change URL / state → locked, target query, excluded for the brand — through
 * ClusterEditor, so Sorgular shows the same record); the page list with category and service
 * (operator edits are locked), "Analiz et" per row and "Seçilenleri analiz et". AI steps run as queued jobs.
 */
final class ClustersPagesTab extends Component
{
    use WithPagination;

    #[Locked]
    public int $assetId = 0;

    #[Url(as: 'kategori')]
    public string $category = '';

    #[Url(as: 'ara')]
    public string $search = '';

    /** @var list<int|string> */
    public array $selected = [];

    /** @var array<int, array{page?: string, state?: string, target?: string, excluded?: bool}> */
    public array $edit = [];

    public string $message = '';

    public function mount(int $assetId): void
    {
        $this->assetId = $assetId;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['category', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function run(string $operation): void
    {
        if (! in_array($operation, [SiteOperations::CATEGORIZE, SiteOperations::SERVICE_PAGES, SiteOperations::CLUSTER_PAGES], true)) {
            return;
        }
        SiteOperations::dispatch($this->assetId, $operation);
        $this->message = SiteOperations::LABELS[$operation].' kuyruğa alındı.';
    }

    public function setCategory(int $pageId, string $category, PageCategorizer $categorizer): void
    {
        $categorizer->setCategory($this->page($pageId), $category);
        $this->message = 'Kategori kaydedildi (kilitli).';
    }

    public function setOffering(int $pageId, string $offeringId, ServicePageMapper $mapper): void
    {
        $mapper->setOffering($this->page($pageId), ctype_digit($offeringId) ? (int) $offeringId : null);
        $this->message = 'Hizmet kaydedildi (kilitli).';
    }

    /** Brand-only edit (same ClusterEditor as Sorgular): URL / state (locked), target query override, excluded. */
    public function saveCluster(int $rowId, ClusterEditor $editor): void
    {
        $row = BrandClusterPage::query()->where('website_asset_id', $this->assetId)->findOrFail($rowId);
        $edit = $this->edit[$rowId] ?? [];
        $page = (string) ($edit['page'] ?? ($row->page_id ?? ''));
        $values = ['target_query_override' => (string) ($edit['target'] ?? $row->target_query_override), 'excluded' => (bool) ($edit['excluded'] ?? $row->excluded)];
        $pageId = ctype_digit($page) ? (int) $page : null;
        $state = (string) ($edit['state'] ?? $row->state);
        if ($pageId !== $row->page_id || $state !== $row->state) {
            $values += ['page_id' => $pageId, 'state' => $state];
        }
        $editor->brandRow($row, $values);
        unset($this->edit[$rowId]);
        $this->message = 'Küme hedefi kaydedildi (bu markaya özel).';
    }

    public function analyze(int $pageId): void
    {
        $this->page($pageId);
        SiteOperations::dispatchUrlAnalysis($this->assetId, [$pageId]);
        $this->message = 'URL analizi kuyruğa alındı.';
    }

    public function analyzeSelected(): void
    {
        $ids = Page::query()->where('website_asset_id', $this->assetId)->whereIn('id', array_map('intval', $this->selected))->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($ids === []) {
            throw ValidationException::withMessages(['selected' => 'Sayfa seçin.']);
        }
        $count = SiteOperations::dispatchUrlAnalysis($this->assetId, $ids);
        $this->selected = [];
        $this->message = $count.' sayfa analize alındı.';
    }

    public function render(SiteMetrics $metrics): View
    {
        $site = DigitalAsset::query()->findOrFail($this->assetId);
        $brand = SiteScope::brandOf($site);
        $offerings = $brand !== null ? SiteScope::offerings($brand) : collect();
        $pages = Page::query()->where('website_asset_id', $site->id)
            ->when($this->category !== '', fn ($q) => $this->category === '__none' ? $q->whereNull('category') : $q->where('category', $this->category))
            ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($s) => $s->where('url', 'like', '%'.trim($this->search).'%')->orWhere('title', 'like', '%'.trim($this->search).'%')))
            ->orderBy('path')->paginate(50, ['id', 'url', 'path', 'title', 'category', 'category_locked', 'category_source', 'language', 'analyzed_at']);
        $rows = BrandClusterPage::query()->with(['cluster.mainQuery', 'cluster.service.primaryName', 'page:id,url,path'])->where('website_asset_id', $site->id)
            ->orderBy('cluster_id')->get();
        foreach ($rows as $row) {
            $this->edit[$row->id] ??= ['page' => (string) ($row->page_id ?? ''), 'state' => (string) $row->state,
                'target' => (string) ($row->target_query_override ?? ''), 'excluded' => (bool) $row->excluded];
        }

        return view('livewire.operator.website.v2.clusters-pages-tab', [
            'pages' => $pages,
            'links' => ServicePageMapper::links($pages->getCollection()->pluck('id')->map(fn ($id): int => (int) $id)->all()),
            'offerings' => $offerings->mapWithKeys(fn (BrandOffering $o): array => [(int) $o->id => $o->displayName()])->all(),
            'totals' => $brand !== null ? $metrics->pageTotals($brand, $site) : [],
            'services' => $rows->groupBy(fn (BrandClusterPage $row): string => (string) ($row->cluster?->service?->primaryName?->raw_label ?? '—')),
            'pageOptions' => Page::query()->where('website_asset_id', $site->id)->whereIn('category', ['hizmet', 'lokasyon', 'blog', 'sss'])->orderBy('path')->limit(500)->pluck('path', 'id')->all(),
            'statuses' => collect([SiteOperations::CATEGORIZE, SiteOperations::SERVICE_PAGES, SiteOperations::CLUSTER_PAGES, SiteOperations::URL_ANALYSIS])
                ->mapWithKeys(fn (string $op): array => [$op => SiteOperations::line(SiteOperations::status($site->id, $op))])->filter()->all(),
        ]);
    }

    private function page(int $pageId): Page
    {
        return Page::query()->where('website_asset_id', $this->assetId)->findOrFail($pageId);
    }
}
