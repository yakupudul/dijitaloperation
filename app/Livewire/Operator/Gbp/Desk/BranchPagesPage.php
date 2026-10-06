<?php

namespace App\Livewire\Operator\Gbp\Desk;

use App\Jobs\Gbp\PrepareBranchPageJob;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\GbpBranchPage;
use App\Models\Page;
use App\Services\Gbp\Desk\BranchPages;
use App\Services\Gbp\Desk\GbpDesk;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * İşletme profilleri › Şube sayfaları (`/gbp/sube-sayfalari`, ADR-079): which profile points to its own page on the
 * brand's site. Missing pages are written by AI from the profile (one or all at once), read and edited here, sent to
 * WordPress as draft pages with local-business markup; once published, one click points the profile's website link
 * at the page. Pages the brand already has are found by area and linked the same way.
 */
#[Layout('operator.layouts.app')]
#[Title('Şube sayfaları')]
final class BranchPagesPage extends Component
{
    use DeskScope;

    /** Profile whose page text is open for reading / editing. */
    #[Url(as: 'isletme')]
    public ?int $open = null;

    /** @var array<string, string> */
    public array $form = [];

    /** Work filter (FILTERS key). */
    #[Url(as: 'durum')]
    public string $filter = '';

    /** Profile whose "pick an existing page" box is open. */
    public ?int $picking = null;

    public string $pageQuery = '';

    /** @var array<string, array{label: string, states: list<string>}> */
    public const array FILTERS = [
        'hazirla' => ['label' => 'Hazırlanacak', 'states' => ['missing']],
        'oku' => ['label' => 'Okunup gönderilecek', 'states' => ['ready', 'failed']],
        'yayinla' => ['label' => 'WordPress’te yayınlanacak', 'states' => ['sent']],
        'bagla' => ['label' => 'Profile bağlanacak', 'states' => ['unlinked']],
        'veri' => ['label' => 'Profil verisi bekleyen', 'states' => ['no_data']],
        'site' => ['label' => 'Web sitesi yok', 'states' => ['no_site']],
        'tamam' => ['label' => 'Tamam', 'states' => ['linked', 'single']],
    ];

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_active, 403);
    }

    public function setFilter(string $filter): void
    {
        $this->filter = $this->filter === $filter || ! isset(self::FILTERS[$filter]) ? '' : $filter;
    }

    public function startPicking(int $assetId): void
    {
        $this->picking = $this->picking === $assetId ? null : $assetId;
        $this->pageQuery = '';
    }

    public function choose(int $assetId, int $pageId, BranchPages $pages): void
    {
        try {
            $pages->choose(auth()->user(), $this->location($assetId), $this->page($assetId, $pageId));
            $this->picking = null;
            $this->say('Sayfa bu şubeye ayrıldı; şimdi profili bu sayfaya bağlayabilirsiniz.');
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    public function unchoose(int $assetId, BranchPages $pages): void
    {
        $pages->unchoose(auth()->user(), $this->location($assetId));
        $this->say('Seçim kaldırıldı.');
    }

    public function sendHub(int $brandId, BranchPages $pages): void
    {
        try {
            $pages->sendHub(auth()->user(), $brandId);
            $this->say('Şubelerimiz sayfası WordPress’e taslak olarak gönderiliyor; kontrol edip yayınlayın.');
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    public function toggle(int $assetId): void
    {
        $this->open = $this->open === $assetId ? null : $assetId;
        $this->form = [];
        $row = $this->open !== null ? GbpBranchPage::query()->where('digital_asset_id', $this->open)->first() : null;
        if ($row !== null) {
            $content = (array) $row->content;
            $this->form = ['title' => (string) ($content['title'] ?? ''), 'slug' => (string) ($content['slug'] ?? ''), 'meta_title' => (string) ($content['meta_title'] ?? ''),
                'meta_description' => (string) ($content['meta_description'] ?? ''), 'intro' => implode("\n\n", (array) ($content['intro'] ?? [])), 'access' => (string) ($content['access'] ?? '')];
        }
    }

    public function prepare(int $assetId): void
    {
        abort_unless($this->canWrite(), 403);
        $this->location($assetId);
        $this->queue($assetId);
        $this->say('Sayfa yazılıyor; hazır olunca bu satırda görünür.');
    }

    /** Writes every missing page of the brands in scope, or of one brand (AI, one job per profile). */
    public function prepareMissing(BranchPages $pages, GbpDesk $desk, ?int $brandId = null): void
    {
        abort_unless($this->canWrite(), 403);
        $locations = $this->scopedLocations()->when($brandId !== null, fn ($c) => $c->where('brand_id', $brandId));
        $states = $pages->states($locations, $desk->snapshots($locations->pluck('id')->map(fn ($id): int => (int) $id)->all()));
        $queued = 0;
        foreach ($states as $assetId => $state) {
            if (in_array($state['state'], ['missing', 'failed'], true) && (Cache::get(PrepareBranchPageJob::stateKey($assetId))['status'] ?? null) !== 'running') {
                $this->queue($assetId);
                $queued++;
            }
        }
        $this->say($queued > 0 ? $queued.' şube sayfası yazılıyor.' : 'Yazılacak eksik sayfa yok.');
    }

    public function save(BranchPages $pages): void
    {
        try {
            $pages->edit(auth()->user(), $this->row(), $this->form);
            $this->say('Kaydedildi; sektör kuralları yeniden kontrol edildi.');
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    public function send(int $assetId, BranchPages $pages): void
    {
        try {
            if ($this->open === $assetId && $this->form !== []) {
                $pages->edit(auth()->user(), $this->row(), $this->form);
            }
            $pages->send(auth()->user(), GbpBranchPage::query()->where('digital_asset_id', $assetId)->firstOrFail());
            $this->say('Sayfa WordPress’e taslak olarak gönderiliyor; işaretleme taslağa eklenecek. WordPress’te kontrol edip yayınlayın.');
            $this->open = null;
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    public function link(int $assetId, int $pageId, BranchPages $pages): void
    {
        try {
            $pages->link(auth()->user(), $this->location($assetId), $this->page($assetId, $pageId));
            $this->say('Profilin web sitesi bağlantısı şube sayfasına çevriliyor.');
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    public function markup(int $assetId, int $pageId, BranchPages $pages): void
    {
        try {
            $pages->markup(auth()->user(), $this->location($assetId), $this->page($assetId, $pageId));
            $this->say('Yerel işletme işaretlemesi sayfaya ekleniyor.');
        } catch (ValidationException $exception) {
            $this->sayError($exception);
        }
    }

    public function discard(int $assetId): void
    {
        abort_unless($this->canWrite(), 403);
        GbpBranchPage::query()->where('digital_asset_id', $assetId)->whereIn('status', [GbpBranchPage::READY, GbpBranchPage::FAILED])->delete();
        $this->open = null;
        $this->say('Taslak silindi.');
    }

    public function render(BranchPages $pages, GbpDesk $desk): View
    {
        if (! isset(self::FILTERS[$this->filter])) {
            $this->filter = '';
        }
        $all = $this->scopedLocations();
        $states = $pages->states($all, $desk->snapshots($all->pluck('id')->map(fn ($id): int => (int) $id)->all()));
        $counts = collect(self::FILTERS)->map(fn (array $f): int => count(array_filter($states, fn (array $s): bool => in_array($s['state'], $f['states'], true))))->all();
        $locations = $this->filter === '' ? $all : $all->filter(fn ($l): bool => in_array($states[$l->id]['state'] ?? '', self::FILTERS[$this->filter]['states'], true));
        $running = $all->mapWithKeys(fn ($l): array => [$l->id => Cache::get(PrepareBranchPageJob::stateKey((int) $l->id))])->filter()->all();
        $markups = ExternalWriteAction::query()->where('action', ExternalWriteAction::ACTION_SITE_FIX)->whereIn('status', ['queued', 'running', 'succeeded', 'partial'])
            ->whereRaw('cast(request_payload as text) like ?', ['%gbp-branch-schema-%'])->latest('id')->limit(500)->get(['id', 'status', 'request_payload'])
            ->mapWithKeys(fn ($a): array => [(int) str_replace('gbp-branch-schema-', '', (string) data_get($a->request_payload, 'changes.0.reference')) => $a->status]);
        $brands = [];
        foreach ($all->groupBy('brand_id') as $brandId => $brandLocations) {
            $brandStates = $brandLocations->map(fn ($l): string => $states[$l->id]['state'] ?? '');
            $brands[(int) $brandId] = [
                'total' => $brandLocations->count(),
                'done' => $brandStates->filter(fn (string $s): bool => in_array($s, BranchPages::DONE, true))->count(),
                'missing' => $brandStates->filter(fn (string $s): bool => in_array($s, ['missing', 'failed'], true))->count(),
                'no_data' => $brandStates->filter(fn (string $s): bool => $s === 'no_data')->count(),
                'hub' => $brandLocations->count() >= BranchPages::HUB_MIN ? $pages->hub((int) $brandId) : null,
            ];
        }
        $pickLocation = $this->picking !== null ? $all->firstWhere('id', $this->picking) : null;

        return view('livewire.operator.gbp.desk.branch-pages', [
            'groups' => $locations->groupBy(fn ($l): string => (string) $l->brand?->name),
            'states' => $states,
            'running' => $running,
            'markups' => $markups,
            'counts' => $counts,
            'filters' => self::FILTERS,
            'brands' => $brands,
            'steps' => BranchPages::STEPS,
            'pickResults' => $pickLocation !== null ? $pages->searchPages($pickLocation, $this->pageQuery) : collect(),
            'openRow' => $this->open !== null ? GbpBranchPage::query()->where('digital_asset_id', $this->open)->first() : null,
            'canWrite' => $this->canWrite(),
            'brandOptions' => $this->brandOptions(),
        ]);
    }

    private function queue(int $assetId): void
    {
        Cache::put(PrepareBranchPageJob::stateKey($assetId), ['status' => 'running', 'message' => 'Yazılıyor…'], now()->addHour());
        PrepareBranchPageJob::dispatch($assetId);
    }

    private function row(): GbpBranchPage
    {
        return GbpBranchPage::query()->where('digital_asset_id', (int) $this->open)->firstOrFail();
    }

    private function page(int $assetId, int $pageId): Page
    {
        $location = $this->location($assetId);
        $sites = DigitalAsset::query()->where('brand_id', $location->brand_id)->where('type', 'website')->pluck('id');

        return Page::query()->whereIn('website_asset_id', $sites)->findOrFail($pageId);
    }
}
