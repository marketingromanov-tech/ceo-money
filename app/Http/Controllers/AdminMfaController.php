<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AdminMfaService;
use App\Services\TotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;
use App\Services\AuditLogService;

class AdminMfaController extends Controller
{
    public function challenge(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('mfa_login_user_id')) return redirect()->route('login');
        return view('auth.mfa-challenge');
    }

    public function verifyChallenge(Request $request, AdminMfaService $mfa): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:64']]);
        $user = User::query()->findOrFail($request->session()->get('mfa_login_user_id'));
        abort_unless($user->role === 'admin' && $user->is_active && $user->mfa_enabled_at, 403);
        $key = 'mfa-login:'.$user->id.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) return back()->withErrors(['code' => 'Слишком много попыток. Повторите позже.']);
        if (! $mfa->verify($user, $data['code'])) {
            RateLimiter::hit($key, 60);
            return back()->withErrors(['code' => 'Неверный код подтверждения.']);
        }
        RateLimiter::clear($key);
        Auth::login($user, (bool) $request->session()->pull('mfa_login_remember', false));
        $request->session()->regenerate();
        $request->session()->put('admin_mfa_user_id', $user->id);
        $request->session()->forget('mfa_login_user_id');
        return redirect()->intended(route('admin.dashboard'));
    }

    public function setup(Request $request, TotpService $totp): View
    {
        $user = $request->user();
        abort_unless($user?->role === 'admin', 403);
        if (! $user->mfa_enabled_at && ! $request->session()->has('mfa_setup_secret')) {
            $request->session()->put('mfa_setup_secret', $totp->generateSecret());
        }
        return view('admin.security', ['setupSecret' => $request->session()->get('mfa_setup_secret'), 'provisioningUri' => $request->session()->has('mfa_setup_secret') ? $totp->provisioningUri($request->session()->get('mfa_setup_secret'), $user->email) : null]);
    }

    public function enable(Request $request, TotpService $totp, AdminMfaService $mfa, AuditLogService $audit): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'string'], 'code' => ['required', 'string']]);
        $user = $request->user();
        $secret = $request->session()->get('mfa_setup_secret');
        if (! Hash::check($data['password'], $user->password)) return back()->withErrors(['password' => 'Неверный пароль.']);
        if (! $secret || ! $totp->verify($secret, $data['code'])) return back()->withErrors(['code' => 'Неверный код приложения.']);
        $user->forceFill(['mfa_secret' => $secret, 'mfa_enabled_at' => now()])->save();
        $codes = $mfa->recoveryCodes($user);
        $request->session()->forget('mfa_setup_secret');
        $request->session()->put(['admin_mfa_user_id' => $user->id, 'recent_auth_at' => now()->timestamp, 'new_recovery_codes' => $codes]);
        $request->session()->regenerate();
        $audit->log('security.mfa_enabled', $user, $user, null, ['user_id' => $user->id]);
        return redirect()->route('admin.security.setup');
    }

    public function regenerateRecovery(Request $request, AdminMfaService $mfa, AuditLogService $audit): RedirectResponse
    {
        $this->assertRecent($request);
        $codes = $mfa->recoveryCodes($request->user());
        $request->session()->put('new_recovery_codes', $codes);
        $audit->log('security.recovery_codes_regenerated', $request->user(), $request->user(), null, ['user_id' => $request->user()->id]);
        return back();
    }

    public function disable(Request $request, AuditLogService $audit): RedirectResponse
    {
        $this->assertRecent($request);
        $user = $request->user();
        $user->forceFill(['mfa_secret' => null, 'mfa_recovery_codes' => null, 'mfa_enabled_at' => null])->save();
        $request->session()->forget(['admin_mfa_user_id', 'recent_auth_at', 'new_recovery_codes']);
        $audit->log('security.mfa_disabled', $user, $user, null, ['user_id' => $user->id]);
        return redirect()->route('admin.security.setup');
    }

    public function recent(Request $request): View
    {
        return view('auth.recent-auth');
    }

    public function verifyRecent(Request $request, AdminMfaService $mfa): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'string'], 'code' => ['required', 'string', 'max:64']]);
        $user = $request->user();
        $key = 'recent-auth:'.$user->id.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) return back()->withErrors(['code' => 'Слишком много попыток. Повторите позже.']);
        if (! Hash::check($data['password'], $user->password) || ! $mfa->verify($user, $data['code'])) {
            RateLimiter::hit($key, 60);
            return back()->withErrors(['code' => 'Не удалось подтвердить личность.']);
        }
        RateLimiter::clear($key);
        $request->session()->put('recent_auth_at', now()->timestamp);
        return redirect()->intended(route('admin.dashboard'));
    }

    private function assertRecent(Request $request): void
    {
        abort_unless((int) $request->session()->get('recent_auth_at') >= now()->subMinutes(10)->timestamp, 403);
    }
}
