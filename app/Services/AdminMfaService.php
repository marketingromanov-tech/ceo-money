<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

final class AdminMfaService
{
    public function __construct(private readonly TotpService $totp, private readonly AuditLogService $audit) {}

    public function verify(User $user, string $code, bool $allowRecovery = true): bool
    {
        if ($user->mfa_secret && $this->totp->verify($user->mfa_secret, $code)) return true;
        if (! $allowRecovery) return false;
        foreach ($user->mfa_recovery_codes ?? [] as $index => $hash) {
            if (Hash::check(trim($code), $hash)) {
                $codes = $user->mfa_recovery_codes;
                unset($codes[$index]);
                $user->forceFill(['mfa_recovery_codes' => array_values($codes)])->save();
                $this->audit->log('security.recovery_code_used', $user, $user, null, ['user_id' => $user->id]);
                return true;
            }
        }
        return false;
    }

    public function recoveryCodes(User $user): array
    {
        $plain = [];
        for ($i = 0; $i < 8; $i++) $plain[] = strtoupper(bin2hex(random_bytes(4)).'-'.bin2hex(random_bytes(2)));
        $user->forceFill(['mfa_recovery_codes' => array_map(fn ($code) => Hash::make($code), $plain)])->save();
        return $plain;
    }
}
