<?php

namespace App\Livewire\Operator\Work;

use App\Models\Brand;
use App\Models\PushSubscription;
use App\Services\Work\ContentBoard;
use App\Services\Work\WorkDesk;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Genel işler (`/work`): all brands' open work in seven tabs — Marka kurulumu, Web site SEO içerikler, Teknik SEO, Teknik sağlık,
 * Google Ads, Meta Ads, Google İşletme — most urgent first. Onayla / Yaptım / Reddet / Ertele act on the item;
 * "Aç" opens the asset screen where approved site and Business Profile changes are written. "Yapıldı" lists the
 * last 30 days with the system's check of each change (confirmed, still seen, noticed by itself).
 */
#[Layout('operator.layouts.app')]
#[Title('Genel işler')]
final class WorkPage extends Component
{
    #[Url(as: 'sekme')]
    public string $tab = 'icerik';

    #[Url(as: 'durum')]
    public string $view = WorkDesk::VIEW_OPEN;

    #[Url(as: 'marka')]
    public ?int $brand = null;

    public int $shown = 60;

    /** @var array<int|string, string> suggestion id => note / reason */
    public array $notes = [];

    public string $message = '';

    /** @var array<int|string, string> content idea id => language picked before "Yaz" */
    public array $languages = [];

    /** Content idea whose article is open to read. */
    public ?int $reading = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_active, 403);
        $this->tab = isset(WorkDesk::TABS[$this->tab]) ? $this->tab : 'icerik';
        $this->view = in_array($this->view, [WorkDesk::VIEW_OPEN, WorkDesk::VIEW_DONE], true) ? $this->view : WorkDesk::VIEW_OPEN;
    }

    public function setTab(string $tab): void
    {
        $this->tab = isset(WorkDesk::TABS[$tab]) ? $tab : 'icerik';
        $this->shown = 60;
        $this->message = '';
    }

    public function setView(string $view): void
    {
        $this->view = $view === WorkDesk::VIEW_DONE ? WorkDesk::VIEW_DONE : WorkDesk::VIEW_OPEN;
        $this->shown = 60;
    }

    public function updatedBrand(): void
    {
        $this->shown = 60;
    }

    public function more(): void
    {
        $this->shown += 60;
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

    /** İçerik kutusu "Yaz" (title approved + writer started) or "+ dil" (a translation of the written article). */
    public function writeContent(int $id, ?string $language = null): void
    {
        $board = app(ContentBoard::class);
        $picked = $language ?? (filled($this->languages[$id] ?? null) ? (string) $this->languages[$id] : null);
        $this->act(fn (): string => $board->write($id, auth()->user(), $picked));
    }

    public function sendContent(int $id, ContentBoard $board): void
    {
        $this->act(fn (): string => $board->send($id, auth()->user()));
        $this->reading = null;
    }

    public function read(int $id): void
    {
        $this->reading = $id;
    }

    public function closeReading(): void
    {
        $this->reading = null;
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
        $rows = $desk->rows($this->tab, $this->view, $this->brand);
        $boxes = $this->tab === 'icerik' && $this->view === WorkDesk::VIEW_OPEN ? $board->boxes($this->brand) : collect();

        return view('livewire.operator.work.work-page', [
            'tabs' => WorkDesk::TABS,
            'counts' => $desk->counts($this->brand),
            'urgent' => $desk->urgent($this->brand),
            'rows' => $rows->take($this->shown),
            'boxes' => $boxes,
            'article' => $this->reading !== null ? $board->article($this->reading) : null,
            'total' => $rows->count(),
            'brands' => Brand::query()->operational()->orderBy('name')->get(['id', 'name']),
            'pushDevices' => PushSubscription::query()->where('user_id', auth()->id())->count(),
        ]);
    }
}
