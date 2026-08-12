<?php

namespace App\Services;

use App\Models\DividendPayment;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Support\WithdrawalTxid;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class DividendWithdrawalService
{
    public function __construct(
        private readonly AvailableBalanceService $balances,
        private readonly AccrualCalculator $decimal,
        private readonly AuditLogService $audit,
        private readonly WithdrawalVerificationService $verification,
        private readonly RecentAdminAuthentication $recentAuth,
    ) {
    }

    public function pay(WithdrawalRequest $request, ?User $admin = null, ?string $txid = null): DividendPayment
    {
        $txid = WithdrawalTxid::normalize($txid);

        try {
            return DB::transaction(function () use ($request, $admin, $txid) {
                $request = WithdrawalRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($request->status === 'paid') {
                return $request->dividendPayment()->firstOrFail();
            }

            if ($request->type !== 'dividend' || $request->status !== 'approved') {
                throw new DomainException('Only an approved dividend withdrawal can be paid.');
            }

            $this->verification->assertReadyForPayout($request);
            $this->recentAuth->assert($admin);

            if ($admin !== null && ! User::query()->whereKey($admin->id)->where('role', 'admin')->where('is_active', true)->exists()) {
                throw new DomainException('Only an active administrator can pay withdrawals.');
            }

            $this->assertUniqueTxid($txid, $request->id);

            if ($this->decimal->compare($request->reserved_amount, $request->requested_amount) < 0) {
                throw new DomainException('Dividend reservation no longer covers the request.');
            }

            $availableIncludingOwnReservation = $this->decimal->add(
                $this->balances->availableDividendBalance($request->investmentAccount),
                $request->reserved_amount,
            );

            if ($this->decimal->compare($request->requested_amount, $availableIncludingOwnReservation) > 0) {
                throw new DomainException('Available dividends are insufficient at payment time.');
            }

            $paidAt = now();
            $payment = DividendPayment::create([
                'withdrawal_request_id' => $request->id,
                'investment_account_id' => $request->investment_account_id,
                'gross_amount' => $request->requested_amount,
                'fee_amount' => $request->fee_amount,
                'net_amount' => $request->net_amount,
                'currency' => $request->currency,
                'wallet_address' => $request->wallet_address_snapshot,
                'network' => $request->network_snapshot,
                'txid' => $txid,
                'paid_at' => $paidAt,
                'paid_by' => $admin?->id,
            ]);

            $old = ['status' => $request->status];
            $request->update([
                'status' => 'paid', 'paid_at' => $paidAt, 'paid_by' => $admin?->id, 'txid' => $txid,
            ]);
            $this->audit->log('withdrawal.dividend_paid', $request, $admin, $old, [
                'status' => 'paid', 'payment_id' => $payment->id, 'txid' => $txid,
            ]);

                return $payment;
            });
        } catch (QueryException $exception) {
            if ($txid !== null && WithdrawalRequest::query()->where('txid', $txid)->whereKeyNot($request->id)->exists()) {
                throw new DomainException('Выплата с таким TXID уже зарегистрирована.', previous: $exception);
            }

            throw $exception;
        }
    }

    private function assertUniqueTxid(?string $txid, int $requestId): void
    {
        if ($txid !== null && WithdrawalRequest::query()->where('txid', $txid)->whereKeyNot($requestId)->exists()) {
            throw new DomainException('Выплата с таким TXID уже зарегистрирована.');
        }
    }
}
