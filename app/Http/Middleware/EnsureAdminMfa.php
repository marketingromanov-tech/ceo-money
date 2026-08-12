<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || $user->role !== 'admin') return $next($request);
        if (app()->environment('testing') && ! $request->session()->get('mfa_enforcement_test')) return $next($request);
        if (! $user->mfa_enabled_at) return redirect()->route('admin.security.setup');
        if ((int) $request->session()->get('admin_mfa_user_id') !== $user->id) {
            auth()->logout();
            $request->session()->forget(['admin_mfa_user_id', 'recent_auth_at']);
            return redirect()->route('login');
        }
        return $next($request);
    }
}
