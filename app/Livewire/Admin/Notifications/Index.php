<?php

namespace App\Livewire\Admin\Notifications;

use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.admin')]
class Index extends Component
{
    use WithPagination;

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
        return view('livewire.admin.notifications.index', [
            'notifications' => auth()->user()->notifications()->latest()->paginate(20),
            'unreadCount' => auth()->user()->unreadNotifications()->count(),
        ])->title('Уведомления — CEO Money');
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->check() && auth()->user()->role === 'admin', 403);
    }
}
