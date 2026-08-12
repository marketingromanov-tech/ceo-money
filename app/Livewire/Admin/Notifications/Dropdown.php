<?php

namespace App\Livewire\Admin\Notifications;

use Livewire\Component;

class Dropdown extends Component
{
    public function markAllRead(): void
    {
        $this->authorizeAdmin();
        auth()->user()->unreadNotifications()->update(['read_at' => now()]);
    }

    public function open(string $notificationId)
    {
        $this->authorizeAdmin();
        $notification = auth()->user()->notifications()->whereKey($notificationId)->firstOrFail();
        $notification->markAsRead();

        return $this->redirect($notification->data['target'] ?? route('admin.notifications.index'), navigate: true);
    }

    public function render()
    {
        $this->authorizeAdmin();
        return view('livewire.admin.notifications.dropdown', [
            'notifications' => auth()->user()->notifications()->latest()->limit(6)->get(),
            'unreadCount' => auth()->user()->unreadNotifications()->count(),
        ]);
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->check() && auth()->user()->role === 'admin', 403);
    }
}
