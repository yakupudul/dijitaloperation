<?php

namespace App\Livewire\Demo;

use App\Services\Notifications\NotificationReadService;
use App\Services\Notifications\NotificationUiActions;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
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
            'items' => $reads->forUser($user, unreadOnly: false, limit: 8),
            'demoItems' => [],
        ]);
    }
}
