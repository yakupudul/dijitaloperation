<?php

namespace App\Livewire\Operator\Work;

use App\Livewire\Operator\Work\Concerns\ActsOnContentIdeas;
use App\Models\Brand;
use App\Models\PushSubscription;
use App\Services\Site\SiteOperations;
use App\Services\Work\ContentBoard;
use App\Services\Work\ContentCoverage;
use App\Services\Work\WorkDesk;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Genel işler (`/work`): all brands' open work in seven tabs — Marka kurulumu, Web site SEO içerikler, Teknik SEO, Teknik sağlık,
 * Google Ads, Meta Ads, Google İşletme — most urgent first, grouped per brand and work type (WorkDesk::groups).
 * Onayla / Yaptım / Reddet / Ertele act on the item; "Aç" opens the asset screen where approved site and Business
 * Profile changes are written. "Yapıldı" lists the last 30 days with the system's check of each change (confirmed,
 * still seen, noticed by itself). The brand filter lives in the URL (`marka`) and is always shown with "Tüm markalar".
 */
#[Layout('operator.layouts.app')]
#[Title('Genel işler')]
final class WorkPage extends Component
{
    use ActsOnContentIdeas;

    #[Url(as: 'sekme')]
    public string $tab = 'icerik';

    #[Url(as: 'durum')]
    public string $view = WorkDesk::VIEW_OPEN;

    /** İçerik fikirleri step: yazilacak | okunacak | gonderildi. */
    #[Url(as: 'adim')]
    public string $step = 'yazilacak';

    #[Url(as: 'marka')]
    public ?int $brand = null;

    /** Brand sections listed (the rest behind "Daha fazla marka"). */
    public int $shown = 20;

    /** @var array<string, bool> work card key => all its rows shown */
    public array $expanded = [];

    /** @var array<int|string, string> suggestion id => note / reason */
    public array $notes = [];

    /** @var list<int> ticked "301 öneriliyor" rows (Seçilenleri 301 ile birleştir) */
    public array $selected = [];

    public string $message = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_active, 403);
        $this->tab = isset(WorkDesk::TABS[$this->tab]) ? $this->tab : 'icerik';
        $this->view = in_array($this->view, [WorkDesk::VIEW_OPEN, WorkDesk::VIEW_DONE], true) ? $this->view : WorkDesk::VIEW_OPEN;
    }

    public function setTab(string $tab): void
    {
        $this->tab = isset(WorkDesk::TABS[$tab]) ? $tab : 'icerik';
        $this->resetList();
        $this->message = '';
    }

    public function setView(string $view): void
    {
        $this->view = $view === WorkDesk::VIEW_DONE ? WorkDesk::VIEW_DONE : WorkDesk::VIEW_OPEN;
        $this->resetList();
    }

    public function updatedBrand(): void
    {
        $this->resetList();
    }

    public function showBrand(int $brandId): void
    {
        $this->brand = $brandId;
        $this->resetList();
    }

    public function clearBrand(): void
    {
        $this->brand = null;
        $this->resetList();
    }

    public function more(): void
    {
        $this->shown += 20;
    }

    public function expand(string $key): void
    {
        $this->expanded[$key] = true;
    }

    /** "Hepsini 7 gün ertele" on one work card. */
    public function snoozeGroup(string $key, WorkDesk $desk): void
    {
        $this->act(fn (): string => $desk->snoozeGroup($this->tab, $key, $this->brand, auth()->user()).' iş 7 gün ertelendi.');
    }

    /** "Tüm 301'leri birleştir" on one card. */
    public function mergeGroup(string $key, WorkDesk $desk): void
    {
        $this->act(fn (): string => $desk->mergeGroup($this->tab, $key, $this->brand, auth()->user()));
        $this->selected = [];
    }

    /** "Seçilenleri 301 ile birleştir": the ticked rows. */
    public function mergeSelected(WorkDesk $desk): void
    {
        $this->act(fn (): string => $desk->mergeMany(array_map('intval', $this->selected), auth()->user()));
        $this->selected = [];
    }

    public function approve(int $id, WorkDesk $desk): void
    {
        $this->act(fn (): string => $desk->approve($id, auth()->user()));
    }

    public function done(int $id, WorkDesk $desk): void
    {
        $this->act(fn (): string => $desk->done($id, auth()->user(), $this->notes[$id] ?? null), $id);
    }

    /** The type's own button (Onayla ve yap, Düzelt, Doğru bırak, 301 ile birleştir, Ayrı kalsın, WordPress'e taslak gönder). */
    public function run(int $id, string $do, WorkDesk $desk): void
    {
        $this->act(fn (): string => $desk->act($id, $do, auth()->user()), $id);
    }

    public function setStep(string $step): void
    {
        $this->step = array_key_exists($step, ContentBoard::STEPS) ? $step : 'yazilacak';
    }

    /** Marka tablosu "Fikir üret": the site's pool is filled now in every language below it, instead of next morning. */
    public function makeTitles(int $siteId, ContentCoverage $coverage): void
    {
        $this->act(function () use ($siteId, $coverage): string {
            $needs = $coverage->needs(false, $siteId);
            if ($needs === []) {
                throw ValidationException::withMessages(['work' => 'Bu sitenin fikir havuzu dolu ya da kümeleri eşleşmedi.']);
            }
            $wants = $needs[0]['wants'];
            SiteOperations::dispatch($siteId, SiteOperations::WEEKLY_CONTENT, ['wants' => $wants]);

            return implode(', ', array_map(fn (string $l, int $n): string => strtoupper($l).' '.$n, array_keys($wants), $wants)).' fikir hazırlanıyor; bitince "Onay bekleyen başlıklar"a düşer.';
        });
    }

    /** "Hepsini onayla ve yazdır" on one site's waiting titles. */
    public function writeAll(int $siteId, ContentBoard $board): void
    {
        $this->act(fn (): string => $board->writeAll($siteId, auth()->user()));
    }

    public function reopen(int $id, WorkDesk $desk): void
    {
        $this->act(function () use ($desk, $id): string {
            $desk->reopen($id);

            return 'Geri alındı; iş yeniden açık.';
        });
    }

    public function dismiss(int $id, WorkDesk $desk): void
    {
        $this->act(function () use ($desk, $id): string {
            $desk->dismiss($id, auth()->user(), $this->notes[$id] ?? null);

            return 'Reddedildi; aynı öneri değişmedikçe geri gelmez.';
        }, $id);
    }

    public function snooze(string $kind, int $id, WorkDesk $desk): void
    {
        $this->act(function () use ($desk, $kind, $id): string {
            $desk->snooze($kind === 'alert' ? 'alert' : 'suggestion', $id, 7, auth()->user());

            return '7 gün ertelendi.';
        });
    }

    private function resetList(): void
    {
        $this->shown = 20;
        $this->expanded = [];
    }

    /** @param  callable(): string  $step */
    private function act(callable $step, ?int $id = null): void
    {
        abort_unless(auth()->user()?->is_active, 403);
        try {
            $this->message = $step();
            if ($id !== null) {
                unset($this->notes[$id]);
            }
        } catch (ValidationException $exception) {
            $this->message = (string) collect($exception->errors())->flatten()->first();
        }
    }

    public function render(WorkDesk $desk, ContentBoard $board): View
    {
        $brands = Brand::query()->operational()->orderBy('name')->get(['id', 'name']);
        if ($this->brand !== null && ! $brands->contains('id', $this->brand)) {
            $this->brand = null; // an old link to a brand that is no longer served: every brand, not an empty page
        }
        $rows = $desk->rows($this->tab, $this->view, $this->brand);
        $sections = WorkDesk::groups($rows, $this->view === WorkDesk::VIEW_OPEN);
        $this->step = array_key_exists($this->step, ContentBoard::STEPS) ? $this->step : 'yazilacak';
        $queue = $this->tab === 'icerik' && $this->view === WorkDesk::VIEW_OPEN ? $board->queue($this->brand) : null;

        return view('livewire.operator.work.work-page', [
            'tabs' => WorkDesk::TABS,
            'counts' => $desk->counts($this->brand),
            'urgent' => $desk->urgent($this->brand),
            'sections' => array_slice($sections, 0, $this->shown),
            'hiddenSections' => max(0, count($sections) - $this->shown),
            'queue' => $queue,
            'coverage' => $queue !== null ? app(ContentCoverage::class)->rows($this->brand) : [],
            'article' => $this->reading !== null ? $board->article($this->reading) : null,
            'total' => $rows->count(),
            'truncated' => $rows->count() >= WorkDesk::LIMIT,
            'brands' => $brands,
            'brandCounts' => $desk->brandCounts($this->tab),
            'brandName' => $this->brand !== null ? $brands->firstWhere('id', $this->brand)?->name : null,
            'pushDevices' => PushSubscription::query()->where('user_id', auth()->id())->count(),
        ]);
    }
}
