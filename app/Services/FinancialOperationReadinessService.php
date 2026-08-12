<?php

namespace App\Services;

use App\Models\DepositRequest;
use App\Models\WithdrawalRequest;
use App\Support\DepositVerificationPresentation;
use App\Support\WithdrawalVerificationPresentation;

class FinancialOperationReadinessService
{
    public function __construct(private readonly DepositRequestService $deposits) {}

    /** @return array{ready:bool,reason:?string} */
    public function deposit(DepositRequest $request): array
    {
        if ($request->status !== 'submitted') return ['ready' => false, 'reason' => 'Заявка ещё не передана на проверку'];
        $checks = $request->relationLoaded('verificationChecks') ? $request->verificationChecks : $request->verificationChecks()->get();
        foreach (DepositVerificationService::KEYS as $key) {
            if ($checks->firstWhere('check_key', $key)?->status !== 'passed') return ['ready' => false, 'reason' => 'Не пройдена проверка: '.DepositVerificationPresentation::label($key)];
        }
        if (! $this->deposits->confirmationPreflight($request)['can_confirm']) return ['ready' => false, 'reason' => 'Нет действующих условий инвестирования'];

        return ['ready' => true, 'reason' => null];
    }

    /** @return array{ready:bool,reason:?string} */
    public function withdrawal(WithdrawalRequest $request): array
    {
        if (! in_array($request->status, ['review', 'approved'], true)) return ['ready' => false, 'reason' => 'Заявка ещё не передана на проверку'];
        $checks = $request->relationLoaded('verificationChecks') ? $request->verificationChecks : $request->verificationChecks()->get();
        foreach (WithdrawalVerificationService::KEYS as $key) {
            if ($checks->firstWhere('check_key', $key)?->status !== 'passed') return ['ready' => false, 'reason' => 'Не пройдена проверка: '.WithdrawalVerificationPresentation::label($key)];
        }
        if ($request->status !== 'approved') return ['ready' => false, 'reason' => 'Заявка проверена, но ещё не одобрена'];
        if (! $request->investorWallet || $request->investorWallet->status !== 'approved') return ['ready' => false, 'reason' => 'Кошелёк инвестора не одобрен'];

        return ['ready' => true, 'reason' => null];
    }
}
