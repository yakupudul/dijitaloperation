<?php

namespace App\Livewire\Demo;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class NotificationBell extends Component
{
    public function markRead(string $id): void
    {
        $user = Auth::user();
        if (! $user) {
            return;
        }

        $notification = $user->notifications()->whereKey($id)->first();
        $notification?->markAsRead();
    }

    public function markAllRead(): void
    {
        Auth::user()?->unreadNotifications->markAsRead();
    }

    public function render(): View
    {
        $user = Auth::user();
        $unread = $user?->unreadNotifications()->latest()->limit(8)->get() ?? collect();
        $recent = $user?->notifications()->latest()->limit(8)->get() ?? collect();

        return view('livewire.demo.notification-bell', [
            'unreadCount' => $user?->unreadNotifications()->count() ?? 0,
            'items' => $recent->isNotEmpty() ? $recent : $unread,
        ]);
    }
}
