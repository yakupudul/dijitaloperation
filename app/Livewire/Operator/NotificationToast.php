<?php

namespace App\Livewire\Operator;

use App\Enums\NotificationKind;
use App\Models\UserNotification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Left-side toast for important notifications (Sorgular: rescan ready, first import done, AI step failed): polled
 * every 60 s, at most one at a time, shown for 8 s, each notification only once (toasted_at) and never after it was read.
 */
final class NotificationToast extends Component
{
    /** @var array{id: int, title: string, url: ?string}|null */
    public ?array $toast = null;

    public function check(): void
    {
        $this->toast = self::next((int) Auth::id());
    }

    public function mount(): void
    {
        $this->check();
    }

    /** @return array{id: int, title: string, url: ?string}|null the next important notification, marked as shown */
    public static function next(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $row = UserNotification::query()->where('recipient_user_id', $userId)
            ->where('notification_kind', NotificationKind::QueriesNotice->value)
            ->whereNull('read_at')->whereNull('archived_at')->whereNull('toasted_at')
            ->orderByDesc('created_at')->orderByDesc('id')->first();
        if ($row === null) {
            return null;
        }
        $row->forceFill(['toasted_at' => now()])->save();
        $url = data_get($row->presentation, 'url');

        return ['id' => (int) $row->id, 'title' => (string) data_get($row->presentation, 'title', ''), 'url' => is_string($url) ? $url : null];
    }

    public function render(): View
    {
        return view('livewire.operator.notification-toast');
    }
}
