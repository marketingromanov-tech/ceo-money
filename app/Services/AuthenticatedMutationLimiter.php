<?php

namespace App\Services;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\RateLimiter;

final class AuthenticatedMutationLimiter
{
    private const LIMITS = [
        'deposit' => [5, 600], 'withdrawal' => [5, 600], 'wallet' => [5, 3600],
        'support-ticket' => [5, 3600], 'support-message' => [20, 3600],
        'capitalization' => [5, 600], 'mark-paid' => [10, 600],
    ];

    public function hit(string $operation, ?User $actor): void
    {
        if (! $actor || $actor->role !== 'investor') return;
        if (app()->environment('testing') && ! session('mutation_rate_limit_test')) return;
        [$limit, $decay] = self::LIMITS[$operation] ?? throw new \InvalidArgumentException('Unknown mutation limiter.');
        $key = "authenticated-mutation:{$operation}:user:{$actor->id}";
        if (RateLimiter::tooManyAttempts($key, $limit)) {
            throw new DomainException('Слишком много попыток. Повторите действие позже.');
        }
        RateLimiter::hit($key, $decay);
    }
}
