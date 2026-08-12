<?php

namespace App\Services;

use App\Models\User;
use DomainException;

final class RecentAdminAuthentication
{
    public function assert(?User $actor): void
    {
        if (! $actor || $actor->role !== 'admin') return;
        if (app()->runningInConsole() && ! app()->runningUnitTests()) return;
        if (app()->environment('testing') && ! session('recent_auth_enforcement_test')) return;
        if ((int) session('recent_auth_at', 0) < now()->subMinutes(10)->timestamp) {
            throw new DomainException('Для выполнения этой операции подтвердите личность.');
        }
    }
}
