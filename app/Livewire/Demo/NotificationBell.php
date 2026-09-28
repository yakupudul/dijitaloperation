<?php

namespace App\Livewire\Demo;

use App\Services\Integrations\ResourceAutomationService;
use App\Services\Notifications\NotificationReadService;
use App\Services\Notifications\NotificationUiActions;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

    /** Feedback of the last one-click action ("Şimdi güncelle"). */
    public string $notice = '';

    /**
     * "Şimdi güncelle" on a stopped account's alert: makes the account due now (same as the button on the account
     * list); the collection runs in the background.
     */
    public function runNow(int $automationId): void
    {
        $user = Auth::user();
        if ($user === null) {
            return;
        }
        try {
            app(ResourceAutomationService::class)->runNow($automationId, $user);
            $this->notice = 'Güncelleme sıraya alındı; birkaç dakika içinde başlar. Sayfadan ayrılabilirsiniz.';
        } catch (ModelNotFoundException) {
            $this->notice = 'Bu hesap artık bulunamadı; Entegrasyonlar\'dan kontrol edin.';
        }
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
     * One bell row. A system alert reads its live explanation (OperationalAlertExplainer): a specific title, Ne oldu /
     * Neden önemli / Ne yapmalısın, the exact page to open, a one-click button and "3. kez · ilk 24 Eyl" when it came
     * back. Other notifications keep their stored title; old English titles are shown in Turkish.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function present(array $item): array
    {
        $when = filled($item['created_at'] ?? null) ? Carbon::parse((string) $item['created_at'])->timezone(config('app.timezone'))->format('d.m.Y H:i') : '';
        $message = is_array($item['operator_message'] ?? null) ? $item['operator_message'] : null;
        if ($message !== null) {
            return array_merge($item, [
                'title' => $message['title'],
                'detail' => $message['what'],
                'why' => $message['why'],
                'action' => $message['action'],
                'url' => $message['link_url'],
                'link_label' => $message['link_label'],
                'button' => $message['button'],
                'repeat_label' => $message['repeat_label'],
                'when' => $when,
            ]);
        }

        $title = (string) ($item['title'] ?? '');
        if (app()->getLocale() === 'tr') {
            $title = strtr($title, [
                'Automatic account updates' => 'Hesap güncellemesi durdu',
                'Datasets reported STALE / BLOCKED by Prompt27' => 'Bazı hesapların verisi güncel değil',
                'Queue backlog: oldest waiting job exceeds policy' => 'Arka plan işleri gecikiyor',
                'Expected workers unavailable' => 'Arka plan işçileri çalışmıyor',
                'Stuck collection run(s) detected' => 'Takılı kalan veri çekimi var',
                'Repeated collection failures' => 'Veri çekimleri üst üste başarısız oluyor',
                'Provider rate limited' => 'Sağlayıcı istek sınırına takılıyor',
                'Provider error rate above policy' => 'Sağlayıcı isteklerinin çoğu hata veriyor',
                'Integration reconnect required' => 'Bağlantı yenilenmeli',
                'Integration authorization expires soon' => 'Bağlantının izni bitiyor',
            ]);
        }
        $summary = trim((string) data_get($item, 'presentation.summary', ''));
        $subject = trim((string) ($item['subject_label'] ?? ''));
        $detail = $summary !== '' ? $summary : ($subject !== '' && $subject !== (string) ($item['title'] ?? '') ? $subject : '');

        return array_merge($item, [
            'title' => $title !== '' ? $title : null,
            'detail' => $detail,
            'why' => '',
            'action' => '',
            'link_label' => null,
            'button' => null,
            'repeat_label' => '',
            'when' => $when,
            'url' => ($item['subject_kind'] ?? null) === 'operational_alert' && Route::has('operator.settings.system-health') ? route('operator.settings.system-health') : null,
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
