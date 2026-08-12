<?php

namespace App\Services;

use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Models\WithdrawalVerificationCheck;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

class WithdrawalVerificationService
{
    public const SYSTEM_KEYS = ['available_balance_verified', 'lock_period_verified', 'fifo_allocation_verified', 'fee_verified', 'wallet_snapshot_exists'];
    public const MANUAL_KEYS = ['investor_identity_verified', 'withdrawal_reason_checked', 'wallet_address_verified', 'network_verified', 'wallet_owner_verified', 'amount_confirmed', 'final_reconciliation'];
    public const KEYS = [...self::SYSTEM_KEYS, ...self::MANUAL_KEYS];

    public function __construct(
        private readonly AvailableBalanceService $balances,
        private readonly AccrualCalculator $decimal,
        private readonly AuditLogService $audit,
    ) {}

    public function initializeForRequest(WithdrawalRequest $request): void
    {
        DB::transaction(function () use ($request) {
            foreach (self::KEYS as $key) $request->verificationChecks()->firstOrCreate(['check_key' => $key], ['status' => 'pending']);
        });
        if (in_array($request->status, ['review', 'approved'], true)) $this->refreshSystemChecks($request);
    }

    public function refreshSystemChecks(WithdrawalRequest $request): void
    {
        $request->refresh();
        $this->ensureInitialized($request);
        if (! in_array($request->status, ['review', 'approved'], true)) return;

        $available = $request->type === 'dividend'
            ? $this->balances->availableDividendsForRequest($request)
            : $this->balances->availableCapitalForRequest($request, Carbon::today());
        $covered = $this->decimal->compare((string) $request->requested_amount, $available) <= 0;
        $balanceSnapshot = ['type' => $request->type, 'available_for_request' => $available, 'requested_amount' => (string) $request->requested_amount];
        $this->setSystem($request, 'available_balance_verified', $covered, $balanceSnapshot);
        $this->setSystem($request, 'lock_period_verified', $request->type === 'dividend' || $covered, $request->type === 'capital' ? $balanceSnapshot : ['not_applicable' => true]);
        $this->setSystem($request, 'fifo_allocation_verified', $request->type === 'dividend' || $covered, $request->type === 'capital' ? ['strategy' => 'existing_fifo_payout_service', ...$balanceSnapshot] : ['not_applicable' => true]);

        $expectedNet = $request->fee_payer === 'company'
            ? (string) $request->requested_amount
            : $this->decimal->subtract((string) $request->requested_amount, (string) $request->fee_amount);
        $feeValid = $request->net_amount !== null && $this->decimal->compare($expectedNet, (string) $request->net_amount) === 0;
        $this->setSystem($request, 'fee_verified', $feeValid, [
            'requested_amount' => (string) $request->requested_amount, 'fee_amount' => (string) $request->fee_amount,
            'net_amount' => (string) $request->net_amount, 'fee_payer' => $request->fee_payer,
            'fee_economic_type_snapshot' => $request->fee_economic_type_snapshot,
        ]);
        $walletValid = filled($request->wallet_address_snapshot) && filled($request->network_snapshot) && filled($request->currency);
        $this->setSystem($request, 'wallet_snapshot_exists', $walletValid, $walletValid ? [
            'address' => $request->wallet_address_snapshot, 'network' => $request->network_snapshot, 'currency' => $request->currency,
        ] : null);
    }

    public function markPassed(WithdrawalRequest $request, string $key, User $admin): WithdrawalVerificationCheck { return $this->markManual($request, $key, 'passed', $admin); }
    public function markFailed(WithdrawalRequest $request, string $key, User $admin): WithdrawalVerificationCheck { return $this->markManual($request, $key, 'failed', $admin); }
    public function resetManualCheck(WithdrawalRequest $request, string $key, User $admin): WithdrawalVerificationCheck { return $this->markManual($request, $key, 'pending', $admin); }

    public function allRequiredPassed(WithdrawalRequest $request): bool
    {
        $this->ensureInitialized($request);
        return $request->verificationChecks()->where('status', 'passed')->count() === count(self::KEYS);
    }

    public function assertReadyForPayout(WithdrawalRequest $request): void
    {
        DB::transaction(function () use ($request) {
            $request = WithdrawalRequest::query()->lockForUpdate()->findOrFail($request->id);
            $this->ensureInitialized($request);
            $request->verificationChecks()->lockForUpdate()->get();
            $request->refresh();
            if ($request->status !== 'approved') throw new DomainException('Выплата доступна только для одобренной заявки.');
            $this->refreshSystemChecks($request);
            if (! $this->allRequiredPassed($request)) throw new DomainException('Завершите все обязательные пункты проверки выплаты.');
        });
    }

    public function summary(WithdrawalRequest $request): array
    {
        $this->ensureInitialized($request);
        $checks = $request->verificationChecks()->with('checkedBy')->get()->keyBy('check_key');
        $passed = $checks->where('status', 'passed')->count();
        return ['passed' => $passed, 'total' => count(self::KEYS), 'complete' => $passed === count(self::KEYS), 'checks' => $checks];
    }

    private function markManual(WithdrawalRequest $request, string $key, string $status, User $admin): WithdrawalVerificationCheck
    {
        $request->refresh();
        if (! in_array($request->status, ['review', 'approved'], true)) throw new DomainException('Checklist доступен только во время проверки и после одобрения.');
        if ($admin->role !== 'admin' || ! $admin->is_active) throw new DomainException('Только активный администратор может выполнять проверку.');
        if (! in_array($key, self::MANUAL_KEYS, true)) throw new DomainException('Системную проверку нельзя изменить вручную.');
        $this->ensureInitialized($request);
        if ($key === 'final_reconciliation' && $status === 'passed' && $request->verificationChecks()->where('check_key', '!=', $key)->where('status', '!=', 'passed')->exists()) {
            throw new DomainException('Сначала завершите все предыдущие проверки.');
        }

        return DB::transaction(function () use ($request, $key, $status, $admin) {
            $check = $request->verificationChecks()->where('check_key', $key)->lockForUpdate()->firstOrFail();
            $old = $check->status;
            $check->update(['status' => $status, 'checked_by_user_id' => $status === 'pending' ? null : $admin->id, 'checked_at' => $status === 'pending' ? null : now(), 'value_snapshot' => $status === 'passed' ? $this->manualSnapshot($request, $key) : null]);
            $action = $status === 'passed' ? 'withdrawal_verification.passed' : ($status === 'failed' ? 'withdrawal_verification.failed' : 'withdrawal_verification.reset');
            $this->audit->log($action, $check, $admin, ['status' => $old], ['withdrawal_request_id' => $request->id, 'investor_id' => $request->investor_id, 'check_key' => $key, 'status' => $status, 'checked_by' => $status === 'pending' ? null : $admin->id, 'checked_at' => $status === 'pending' ? null : $check->checked_at?->toDateTimeString()]);
            return $check;
        });
    }

    private function manualSnapshot(WithdrawalRequest $request, string $key): ?array
    {
        return match ($key) {
            'wallet_address_verified' => ['address' => $request->wallet_address_snapshot],
            'network_verified' => ['network' => $request->network_snapshot],
            'amount_confirmed', 'final_reconciliation' => ['requested_amount' => (string) $request->requested_amount, 'fee_amount' => (string) $request->fee_amount, 'net_amount' => (string) $request->net_amount],
            default => null,
        };
    }

    private function setSystem(WithdrawalRequest $request, string $key, bool $passed, ?array $snapshot): void
    {
        $request->verificationChecks()->where('check_key', $key)->firstOrFail()->update(['status' => $passed ? 'passed' : 'failed', 'checked_by_user_id' => null, 'checked_at' => now(), 'value_snapshot' => $snapshot]);
    }

    private function ensureInitialized(WithdrawalRequest $request): void
    {
        foreach (self::KEYS as $key) $request->verificationChecks()->firstOrCreate(['check_key' => $key], ['status' => 'pending']);
    }
}
