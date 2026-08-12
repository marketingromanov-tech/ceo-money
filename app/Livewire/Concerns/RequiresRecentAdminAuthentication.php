<?php

namespace App\Livewire\Concerns;

trait RequiresRecentAdminAuthentication
{
    protected function requireRecentAdminAuthentication(): bool
    {
        if (app()->environment('testing') && ! session('recent_auth_enforcement_test')) return true;
        $authenticatedAt = (int) session('recent_auth_at', 0);
        if ($authenticatedAt >= now()->subMinutes(10)->timestamp) return true;
        session()->put('url.intended', request()->header('Referer', url()->current()));
        $this->redirectRoute('admin.recent-auth', navigate: false);
        return false;
    }
}
