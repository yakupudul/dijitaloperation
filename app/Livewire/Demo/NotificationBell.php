<?php

namespace App\Livewire\Demo;

use App\Support\Demo\DemoNotificationFixtures;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class NotificationBell extends Component
{
    public function markRead(string $id): void
    {
        $user = Auth::user();
        if ($user) {
            $notification = $user->notifications()->whereKey($id)->first();
            if ($notification) {
                $notification->markAsRead();

                return;
            }
        }

        DemoState::markDemoNotificationRead($id);
    }

    public function markAllRead(): void
    {
        Auth::user()?->unreadNotifications->markAsRead();
        DemoState::markAllDemoNotificationsRead();
    }

    public function render(): View
    {
        $user = Auth::user();
        $dbItems = $user?->notifications()->latest()->limit(8)->get() ?? collect();

        if ($dbItems->isNotEmpty()) {
            return view('livewire.demo.notification-bell', [
                'unreadCount' => $user?->unreadNotifications()->count() ?? 0,
                'items' => $dbItems,
                'demoItems' => [],
            ]);
        }

        $demoItems = DemoState::demoNotifications();
        $unread = collect($demoItems)->where('read', false)->count();

        return view('livewire.demo.notification-bell', [
            'unreadCount' => $unread,
            'items' => collect(),
            'demoItems' => $demoItems,
        ]);
    }
}
