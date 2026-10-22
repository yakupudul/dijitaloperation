<?php

namespace App\Livewire\Operator\Flow;

use App\Services\Work\FlowFeed;
use App\Services\Work\FlowRetry;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The live part of Akış: the four counts (Sırada / Çalışıyor / Bugün bitti / Hata), the heading and the job log.
 * Polls every 3 s while something works or waits, every 15 s otherwise, and only while the tab is visible. A row opens
 * to its items; "Yeniden dene" sends failures again the way they first went (FlowRetry).
 */
final class FlowLive extends Component
{
    public const array STATES = ['queue' => 'Sırada', 'run' => 'Çalışıyor', 'done' => 'Bitti', 'err' => 'Hata var'];

    #[Url(as: 'durum', except: '')]
    public string $state = '';

    public string $opened = '';

    public string $message = '';

    public function filter(string $state): void
    {
        $this->state = $this->state === $state || ! isset(self::STATES[$state]) ? '' : $state;
    }

    public function toggle(string $key): void
    {
        $this->opened = $this->opened === $key ? '' : $key;
    }

    public function retry(string $key, FlowRetry $retry): void
    {
        $this->authorizeAdmin();
        $this->say($retry->retry(auth()->user(), $key));
    }

    public function retryItem(string $item, FlowRetry $retry): void
    {
        $this->authorizeAdmin();
        $this->say($retry->retryItem(auth()->user(), $item));
    }

    public function retryAll(FlowRetry $retry): void
    {
        $this->authorizeAdmin();
        $this->say($retry->retry(auth()->user()));
    }

    public function render(FlowFeed $feed): View
    {
        $counts = $feed->counts();
        $rows = $feed->rows($this->state !== '' ? $this->state : null);

        return view('livewire.operator.flow.flow-live', [
            'counts' => $counts,
            'rows' => $rows,
            'detail' => $this->opened !== '' ? $feed->detail($this->opened) : null,
            'busy' => $counts['run'] > 0 || $counts['queue'] > 0,
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
        ]);
    }

    /** @param  array{sent: int, left: int}  $result */
    private function say(array $result): void
    {
        $this->message = match (true) {
            $result['sent'] === 0 && $result['left'] === 0 => 'Yeniden denenecek hatalı iş yok.',
            $result['sent'] === 0 => 'Bu işler buradan yeniden gönderilemiyor ('.$result['left'].'); nedenleri satırda yazıyor.',
            default => $result['sent'].' iş yeniden sıraya alındı'.($result['left'] > 0 ? ', '.$result['left'].' iş buradan gönderilemiyor' : '').'. İlerlemesi burada görünür.',
        };
        $this->dispatch('operator-notice', message: $this->message, tone: $result['sent'] > 0 ? 'success' : 'info');
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
    }
}
