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

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_active, 403);
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

    /** Writes every missing page of the brands in scope (AI, one job per profile). */
    public function prepareMissing(BranchPages $pages, GbpDesk $desk): void
    {
        abort_unless($this->canWrite(), 403);
        $locations = $this->scopedLocations();
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
        $locations = $this->scopedLocations();
        $states = $pages->states($locations, $desk->snapshots($locations->pluck('id')->map(fn ($id): int => (int) $id)->all()));
        $running = $locations->mapWithKeys(fn ($l): array => [$l->id => Cache::get(PrepareBranchPageJob::stateKey((int) $l->id))])->filter()->all();
        $markups = ExternalWriteAction::query()->where('action', ExternalWriteAction::ACTION_SITE_FIX)->whereIn('status', ['queued', 'running', 'succeeded', 'partial'])
            ->where('request_payload', 'like', '%gbp-branch-schema-%')->latest('id')->limit(500)->get(['id', 'status', 'request_payload'])
            ->mapWithKeys(fn ($a): array => [(int) str_replace('gbp-branch-schema-', '', (string) data_get($a->request_payload, 'changes.0.reference')) => $a->status]);
        $counts = collect($states)->countBy('state')->all();

        return view('livewire.operator.gbp.desk.branch-pages', [
            'groups' => $locations->groupBy(fn ($l): string => (string) $l->brand?->name),
            'states' => $states,
            'running' => $running,
            'markups' => $markups,
            'counts' => $counts,
            'labels' => BranchPages::STATES,
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
