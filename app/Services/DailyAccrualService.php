<?php

namespace App\Services;

use App\Models\AccrualPause;
use App\Models\DailyAccrual;
use App\Models\InvestmentLot;
use App\Models\InvestmentTerm;
use Carbon\Carbon;

class DailyAccrualService
{
    public function __construct(private readonly AccrualCalculator $calculator)
    {
    }

    public function calculateForLot(InvestmentLot $lot, Carbon $date): ?DailyAccrual
    {
        $date = $date->copy()->startOfDay();

        if (
            $lot->status !== 'active'
            || $this->isZeroOrNegative((string) $lot->remaining_amount)
            || $date->lt($lot->accrual_start_date)
        ) {
            return null;
        }

        $rate = $this->effectiveRate($lot, $date);
        $daysInMonth = $date->daysInMonth;
        $isPaused = $this->isPaused($lot, $date);
        $calculatedAmount = $isPaused
            ? '0.00000000'
            : $this->calculator->calculate((string) $lot->remaining_amount, $rate, $daysInMonth);

        $accrual = DailyAccrual::firstOrNew([
            'investment_lot_id' => $lot->id,
            'accrual_date' => $date->toDateString(),
        ]);
        $adjustmentAmount = $accrual->exists
            ? (string) $accrual->adjustment_amount
            : '0.00000000';

        $accrual->fill([
            'investment_account_id' => $lot->investment_account_id,
            'principal_amount' => $lot->remaining_amount,
            'monthly_rate' => $rate,
            'days_in_month' => $daysInMonth,
            'calculated_amount' => $calculatedAmount,
            'adjustment_amount' => $adjustmentAmount,
            'final_amount' => $this->calculator->add($calculatedAmount, $adjustmentAmount),
            'status' => 'calculated',
            'calculated_at' => now(),
        ]);
        $accrual->save();

        return $accrual;
    }

    public function recalculateForLot(InvestmentLot $lot, Carbon $from, Carbon $to): void
    {
        $date = $from->copy()->startOfDay();
        $lastDate = $to->copy()->startOfDay();

        while ($date->lte($lastDate)) {
            $this->calculateForLot($lot, $date);
            $date->addDay();
        }
    }

    private function effectiveRate(InvestmentLot $lot, Carbon $date): string
    {
        $query = InvestmentTerm::query()
            ->where('investment_account_id', $lot->investment_account_id)
            ->whereDate('valid_from', '<=', $date)
            ->where(function ($query) use ($date) {
                $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date);
            });

        $lotTerm = (clone $query)
            ->where('investment_lot_id', $lot->id)
            ->latest('valid_from')
            ->latest('id')
            ->first();

        if ($lotTerm !== null) {
            return (string) $lotTerm->monthly_rate;
        }

        // Account terms define the conditions for newly created lots. Once a lot
        // exists, its rate is an immutable snapshot unless an explicit lot-level
        // term intentionally overrides it.
        return (string) $lot->monthly_rate;
    }

    private function isPaused(InvestmentLot $lot, Carbon $date): bool
    {
        return AccrualPause::query()
            ->where('investment_account_id', $lot->investment_account_id)
            ->where(function ($query) use ($lot) {
                $query->whereNull('investment_lot_id')->orWhere('investment_lot_id', $lot->id);
            })
            ->whereDate('start_date', '<=', $date)
            ->where(function ($query) use ($date) {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', $date);
            })
            ->exists();
    }

    private function isZeroOrNegative(string $amount): bool
    {
        return str_starts_with(trim($amount), '-')
            || preg_match('/^\+?0+(?:\.0+)?$/', trim($amount)) === 1;
    }
}
