<?php

namespace App\Livewire\Demo;

use App\Services\Notifications\NotificationReadService;
use App\Services\Notifications\NotificationUiActions;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

/**
 * In-app notification bell. Reads/writes UserNotification only — no Demo fallback, no Mail.
 */
class NotificationBell extends Component
{
    public function markRead(string $id): void
    {
        $user = Auth::user();
        if ($user === null) {
            return;
        }

        app(NotificationUiActions::class)->markRead($user, $id);
    }

    public function markAllRead(): void
    {
        $user = Auth::user();
        if ($user === null) {
            return;
        }

        app(NotificationUiActions::class)->markAllRead($user);
    }

    /**
     * Older system alerts were stored in English and repeated the title as the subject line; show them in plain
     * Turkish with the alert summary (or the time) underneath and a link to the Alerts page.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function present(array $item): array
    {
        $title = (string) ($item['title'] ?? '');
        if (app()->getLocale() === 'tr') {
            $title = strtr($title, [
                'Automatic account updates' => 'Hesap güncellemesi durdu',
                'Datasets reported STALE / BLOCKED by Prompt27' => 'Bazı veriler güncel değil ya da çekilemiyor',
                'Queue backlog: oldest waiting job exceeds policy' => 'Kuyrukta bekleyen iş birikti',
                'Expected workers unavailable' => 'Arka plan işçileri çalışmıyor',
                'Stuck collection run(s) detected' => 'Takılı kalan veri çekimi var',
                'Repeated collection failures' => 'Veri çekimleri tekrar tekrar başarısız',
                'Provider rate limited' => 'Sağlayıcı istek sınırına takıldı',
                'Provider error rate above policy' => 'Sağlayıcıdan çok fazla hata dönüyor',
                'Integration reconnect required' => 'Entegrasyon yeniden bağlanmalı',
                'Integration authorization expires soon' => 'Entegrasyon yetkisi yakında bitiyor',
            ]);
        }
        $summary = trim((string) data_get($item, 'presentation.summary', ''));
        $subject = trim((string) ($item['subject_label'] ?? ''));
        $detail = $summary !== '' ? $summary : ($subject !== '' && $subject !== (string) ($item['title'] ?? '') ? $subject : '');
        $when = filled($item['created_at'] ?? null) ? Carbon::parse((string) $item['created_at'])->timezone(config('app.timezone'))->format('d.m.Y H:i') : '';

        return array_merge($item, [
            'title' => $title !== '' ? $title : null,
            'detail' => $detail,
            'when' => $when,
            'url' => ($item['subject_kind'] ?? null) === 'operational_alert' && Route::has('operator.alerts') ? route('operator.alerts') : null,
        ]);
    }

    public function render(): View
    {
        $user = Auth::user();
        if ($user === null) {
            return view('livewire.demo.notification-bell', [
                'unreadCount' => 0,
                'items' => [],
                'demoItems' => [],
            ]);
        }

        $reads = app(NotificationReadService::class);

        return view('livewire.demo.notification-bell', [
            'unreadCount' => $reads->unreadCount($user),
            'items' => array_map(fn (array $item): array => self::present($item), $reads->forUser($user, unreadOnly: false, limit: 8)),
            'demoItems' => [],
        ]);
    }
}
