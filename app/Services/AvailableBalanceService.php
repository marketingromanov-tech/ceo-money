<?php

namespace App\Services;

use App\Models\InvestmentAccount;
use App\Models\WithdrawalRequest;
use Carbon\Carbon;

class AvailableBalanceService
{
    public function __construct(private readonly AccrualCalculator $decimal)
    {
    }

    public function availableDividendBalance(InvestmentAccount $account): string
    {
        $accrued = $this->sum($account->dailyAccruals()->pluck('final_amount'));
        $paid = $this->sum($account->withdrawalRequests()
            ->where('type', 'dividend')->where('status', 'paid')->pluck('requested_amount'));
        $reserved = $this->sum($account->withdrawalRequests()
            ->where('type', 'dividend')
            ->whereIn('status', WithdrawalRequest::ACTIVE_RESERVATION_STATUSES)
            ->pluck('reserved_amount'));

        $capitalized = $this->sum($account->dividendCapitalizations()
            ->where('status', 'completed')
            ->pluck('requested_amount'));

        return $this->nonNegative(
            $this->decimal->subtract(
                $this->decimal->subtract($this->decimal->subtract($accrued, $paid), $reserved),
                $capitalized,
            ),
        );
    }

    public function availableCapitalForWithdrawal(InvestmentAccount $account, Carbon $date): string
    {
        $capital = $this->sum($account->investmentLots()
            ->where('remaining_amount', '>', 0)
            ->where(fn ($query) => $query->whereNull('unlock_date')->orWhereDate('unlock_date', '<=', $date))
            ->pluck('remaining_amount'));
        $reserved = $this->sum($account->withdrawalRequests()
            ->where('type', 'capital')
            ->whereIn('status', WithdrawalRequest::ACTIVE_RESERVATION_STATUSES)
            ->pluck('reserved_amount'));

        return $this->nonNegative($this->decimal->subtract($capital, $reserved));
    }

    public function availableDividendsForRequest(WithdrawalRequest $request): string
    {
        $account = $request->investmentAccount;
        $accrued = $this->sum($account->dailyAccruals()->pluck('final_amount'));
        $paid = $this->sum($account->withdrawalRequests()->where('type', 'dividend')->where('status', 'paid')->pluck('requested_amount'));
        $otherReserved = $this->sum($account->withdrawalRequests()->whereKeyNot($request->id)->where('type', 'dividend')->whereIn('status', WithdrawalRequest::ACTIVE_RESERVATION_STATUSES)->pluck('reserved_amount'));
        $capitalized = $this->sum($account->dividendCapitalizations()->where('status', 'completed')->pluck('requested_amount'));

        return $this->nonNegative($this->decimal->subtract($this->decimal->subtract($this->decimal->subtract($accrued, $paid), $otherReserved), $capitalized));
    }

    public function availableCapitalForRequest(WithdrawalRequest $request, Carbon $date): string
    {
        $account = $request->investmentAccount;
        $capital = $this->sum($account->investmentLots()->where('remaining_amount', '>', 0)->where(fn ($query) => $query->whereNull('unlock_date')->orWhereDate('unlock_date', '<=', $date))->pluck('remaining_amount'));
        $otherReserved = $this->sum($account->withdrawalRequests()->whereKeyNot($request->id)->where('type', 'capital')->whereIn('status', WithdrawalRequest::ACTIVE_RESERVATION_STATUSES)->pluck('reserved_amount'));

        return $this->nonNegative($this->decimal->subtract($capital, $otherReserved));
    }

    private function sum(iterable $amounts): string
    {
        $sum = '0.00000000';

        foreach ($amounts as $amount) {
            $sum = $this->decimal->add($sum, (string) $amount);
        }

        return $sum;
    }

    private function nonNegative(string $amount): string
    {
        return $this->decimal->compare($amount, '0') < 0 ? '0.00000000' : $amount;
    }
}
