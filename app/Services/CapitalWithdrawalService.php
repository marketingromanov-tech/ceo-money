<?php

namespace App\Services;

use App\Models\CapitalWithdrawalAllocation;
use App\Models\InvestmentTerm;
use App\Models\InvestmentTransaction;
use App\Models\User;
use App\Models\WithdrawalRequest;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

class CapitalWithdrawalService
{
    public function __construct(
        private readonly AccrualCalculator $decimal,
        private readonly AuditLogService $audit,
    ) {
    }

    public function pay(WithdrawalRequest $request, ?User $admin = null, ?string $txid = null): WithdrawalRequest
    {
        return DB::transaction(function () use ($request, $admin, $txid) {
            $request = WithdrawalRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($request->status === 'paid') {
                return $request;
            }

            if ($request->type !== 'capital' || $request->status !== 'approved') {
                throw new DomainException('Only an approved capital withdrawal can be paid.');
            }

            $paidAt = Carbon::now();
            $lots = $request->investmentAccount->investmentLots()
                ->where('remaining_amount', '>', 0)
                ->where(fn ($query) => $query->whereNull('unlock_date')->orWhereDate('unlock_date', '<=', $paidAt))
                ->orderBy('received_at')->orderBy('id')
                ->lockForUpdate()->get();
            $eligibleCapital = $this->sum($lots->pluck('remaining_amount'));
            $otherReserved = $this->sum($request->investmentAccount->withdrawalRequests()
                ->whereKeyNot($request->id)
                ->where('type', 'capital')
                ->whereIn('status', WithdrawalRequest::ACTIVE_RESERVATION_STATUSES)
                ->pluck('reserved_amount'));
            $available = $this->decimal->subtract($eligibleCapital, $otherReserved);

            if ($this->decimal->compare($request->requested_amount, $available) > 0) {
                throw new DomainException('Available capital is insufficient at payment time.');
            }

            $term = $this->accountTerm($request, $paidAt);
            $afterPayment = $this->decimal->subtract($available, $request->requested_amount);

            if ($term !== null && $this->decimal->compare($afterPayment, $term->minimum_balance) < 0) {
                throw new DomainException('Minimum balance requirement would be violated at payment time.');
            }

            $remainingToAllocate = (string) $request->requested_amount;

            foreach ($lots as $lot) {
                if ($this->decimal->compare($remainingToAllocate, '0') === 0) {
                    break;
                }

                $amount = $this->decimal->compare($lot->remaining_amount, $remainingToAllocate) <= 0
                    ? (string) $lot->remaining_amount
                    : $remainingToAllocate;
                $lotRemaining = $this->decimal->subtract($lot->remaining_amount, $amount);

                CapitalWithdrawalAllocation::create([
                    'withdrawal_request_id' => $request->id,
                    'investment_lot_id' => $lot->id,
                    'amount' => $amount,
                ]);
                $lot->update([
                    'remaining_amount' => $lotRemaining,
                    'status' => $this->decimal->compare($lotRemaining, '0') === 0 ? 'closed' : $lot->status,
                ]);
                $remainingToAllocate = $this->decimal->subtract($remainingToAllocate, $amount);
            }

            if ($this->decimal->compare($remainingToAllocate, '0') !== 0) {
                throw new DomainException('FIFO allocation could not cover the withdrawal.');
            }

            InvestmentTransaction::create([
                'investment_account_id' => $request->investment_account_id,
                'type' => 'withdrawal',
                'amount' => $request->requested_amount,
                'currency' => $request->currency,
                'effective_date' => $paidAt->toDateString(),
                'status' => 'confirmed',
                'comment' => "Capital withdrawal request #{$request->id}",
                'created_by' => $admin?->id,
                'confirmed_by' => $admin?->id,
                'confirmed_at' => $paidAt,
            ]);

            $old = ['status' => $request->status];
            $request->update([
                'status' => 'paid', 'paid_at' => $paidAt, 'paid_by' => $admin?->id, 'txid' => $txid,
            ]);
            $this->audit->log('withdrawal.capital_paid', $request, $admin, $old, [
                'status' => 'paid', 'requested_amount' => $request->requested_amount, 'txid' => $txid,
            ]);

            return $request;
        });
    }

    private function accountTerm(WithdrawalRequest $request, Carbon $date): ?InvestmentTerm
    {
        return InvestmentTerm::query()
            ->where('investment_account_id', $request->investment_account_id)
            ->whereNull('investment_lot_id')
            ->whereDate('valid_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date))
            ->latest('valid_from')->latest('id')->first();
    }

    private function sum(iterable $amounts): string
    {
        $sum = '0.00000000';
        foreach ($amounts as $amount) {
            $sum = $this->decimal->add($sum, (string) $amount);
        }

        return $sum;
    }
}
