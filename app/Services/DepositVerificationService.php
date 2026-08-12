<?php

namespace App\Services;

use App\Models\DepositRequest;
use App\Models\DepositVerificationCheck;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class DepositVerificationService
{
    public const SYSTEM_KEYS = ['investment_terms_active', 'fee_and_net_verified', 'duplicate_txid_checked'];
    public const MANUAL_KEYS = [
        'provider_opened', 'currency_verified', 'network_verified', 'address_verified',
        'incoming_transaction_found', 'txid_verified', 'transaction_status_verified',
        'received_amount_verified', 'sender_checked', 'final_reconciliation',
    ];
    public const KEYS = [
        'investment_terms_active', 'provider_opened', 'currency_verified', 'network_verified',
        'address_verified', 'incoming_transaction_found', 'txid_verified',
        'transaction_status_verified', 'received_amount_verified', 'sender_checked',
        'fee_and_net_verified', 'duplicate_txid_checked', 'final_reconciliation',
    ];

    public function __construct(
        private readonly DepositRequestService $deposits,
        private readonly AccrualCalculator $decimal,
        private readonly AuditLogService $audit,
    ) {}

    public function initializeForRequest(DepositRequest $request): void
    {
        DB::transaction(function () use ($request) {
            foreach (self::KEYS as $key) {
                $request->verificationChecks()->firstOrCreate(['check_key' => $key], ['status' => 'pending']);
            }
        });
        $this->refreshSystemChecks($request);
    }

    public function refreshSystemChecks(DepositRequest $request): void
    {
        $request->refresh();
        $this->ensureInitialized($request);
        if (! in_array($request->status, ['pending', 'payment_submitted', 'submitted'], true)) return;
        $preflight = $this->deposits->confirmationPreflight($request);
        $term = $preflight['term'];
        $this->setSystem($request, 'investment_terms_active', $term !== null, $term ? [
            'investment_term_id' => $term->id,
            'monthly_rate' => (string) $term->monthly_rate,
            'lock_months' => $term->lock_months,
            'accrual_start_date' => $preflight['date']->toDateString(),
        ] : null);

        $duplicateFree = $request->txid !== null && ! DepositRequest::query()
            ->where('txid', $request->txid)->whereKeyNot($request->id)->exists();
        $this->setSystem($request, 'duplicate_txid_checked', $duplicateFree, $request->txid ? ['txid' => $request->txid] : null);

        $consistent = false;
        if ($request->received_amount !== null && $request->net_investment_amount !== null) {
            $expected = $request->fee_payer === 'company'
                ? (string) $request->received_amount
                : $this->decimal->subtract((string) $request->received_amount, (string) $request->fee_amount);
            $consistent = $this->decimal->compare($expected, (string) $request->net_investment_amount) === 0;
        }
        $this->setSystem($request, 'fee_and_net_verified', $consistent, $consistent ? [
            'received_amount' => (string) $request->received_amount,
            'fee_amount' => (string) $request->fee_amount,
            'net_investment_amount' => (string) $request->net_investment_amount,
            'fee_payer' => $request->fee_payer,
        ] : null);
    }

    public function markPassed(DepositRequest $request, string $key, User $admin): DepositVerificationCheck
    {
        return $this->markManual($request, $key, 'passed', $admin);
    }

    public function markFailed(DepositRequest $request, string $key, User $admin): DepositVerificationCheck
    {
        return $this->markManual($request, $key, 'failed', $admin);
    }

    public function resetManualCheck(DepositRequest $request, string $key, User $admin): DepositVerificationCheck
    {
        return $this->markManual($request, $key, 'pending', $admin);
    }

    public function allRequiredPassed(DepositRequest $request): bool
    {
        $this->ensureInitialized($request);
        return $request->verificationChecks()->where('status', 'passed')->count() === count(self::KEYS);
    }

    public function assertReadyForConfirmation(DepositRequest $request): void
    {
        DB::transaction(function () use ($request) {
            $request = DepositRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($request->status !== 'submitted') {
                throw new DomainException('Эта заявка на пополнение не может быть подтверждена.');
            }

            $this->ensureInitialized($request);
            $request->verificationChecks()->lockForUpdate()->get();
            $this->refreshSystemChecks($request);

            if (! $this->allRequiredPassed($request)) {
                throw new DomainException('Не завершена обязательная проверка поступления.');
            }
        });
    }

    public function summary(DepositRequest $request): array
    {
        $this->ensureInitialized($request);
        $checks = $request->verificationChecks()->with('checkedBy')->get()->keyBy('check_key');
        $passed = $checks->where('status', 'passed')->count();
        return ['passed' => $passed, 'total' => count(self::KEYS), 'complete' => $passed === count(self::KEYS), 'checks' => $checks];
    }

    private function markManual(DepositRequest $request, string $key, string $status, User $admin): DepositVerificationCheck
    {
        $this->assertMutable($request);
        if ($admin->role !== 'admin' || ! $admin->is_active) throw new DomainException('Только активный администратор может выполнять проверку.');
        if (! in_array($key, self::MANUAL_KEYS, true)) throw new DomainException('Системную проверку нельзя изменить вручную.');
        if ($key === 'final_reconciliation' && $status === 'passed' && ! $this->previousChecksPassed($request)) {
            throw new DomainException('Сначала завершите все предыдущие проверки.');
        }

        return DB::transaction(function () use ($request, $key, $status, $admin) {
            $check = $request->verificationChecks()->where('check_key', $key)->lockForUpdate()->firstOrFail();
            $old = $check->status;
            $snapshot = $status === 'passed' ? $this->snapshot($request, $key) : null;
            $check->update([
                'status' => $status,
                'checked_by_user_id' => $status === 'pending' ? null : $admin->id,
                'checked_at' => $status === 'pending' ? null : now(),
                'value_snapshot' => $snapshot,
            ]);
            $action = match ($status) { 'passed' => 'deposit_verification.passed', 'failed' => 'deposit_verification.failed', default => 'deposit_verification.reset' };
            $this->audit->log($action, $check, $admin, ['status' => $old], [
                'deposit_request_id' => $request->id, 'investor_id' => $request->investor_id,
                'check_key' => $key, 'status' => $status,
                'checked_by' => $status === 'pending' ? null : $admin->id,
                'checked_at' => $status === 'pending' ? null : $check->checked_at?->toDateTimeString(),
            ]);
            return $check;
        });
    }

    private function snapshot(DepositRequest $request, string $key): ?array
    {
        return match ($key) {
            'provider_opened' => ['provider' => $request->provider_snapshot],
            'currency_verified' => ['currency' => $request->currency],
            'network_verified' => ['network' => $request->network],
            'address_verified' => ['address' => $request->deposit_address_snapshot],
            'txid_verified' => ['txid' => $request->txid],
            'received_amount_verified' => ['received_amount' => (string) $request->received_amount],
            default => null,
        };
    }

    private function setSystem(DepositRequest $request, string $key, bool $passed, ?array $snapshot): void
    {
        $request->verificationChecks()->where('check_key', $key)->firstOrFail()->update([
            'status' => $passed ? 'passed' : 'failed', 'checked_by_user_id' => null,
            'checked_at' => now(), 'value_snapshot' => $snapshot,
        ]);
    }

    private function previousChecksPassed(DepositRequest $request): bool
    {
        return $request->verificationChecks()->where('check_key', '!=', 'final_reconciliation')
            ->where('status', '!=', 'passed')->doesntExist();
    }

    private function assertMutable(DepositRequest $request): void
    {
        $request->refresh();
        if (! in_array($request->status, ['pending', 'payment_submitted', 'submitted'], true)) throw new DomainException('Checklist завершённой заявки доступен только для чтения.');
        $this->ensureInitialized($request);
    }

    private function ensureInitialized(DepositRequest $request): void
    {
        if ($request->verificationChecks()->count() !== count(self::KEYS)) {
            foreach (self::KEYS as $key) $request->verificationChecks()->firstOrCreate(['check_key' => $key], ['status' => 'pending']);
        }
    }
}
