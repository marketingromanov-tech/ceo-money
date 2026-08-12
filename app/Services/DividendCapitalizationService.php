<?php

namespace App\Services;

use App\Models\DividendCapitalization;
use App\Models\InvestmentAccount;
use App\Models\InvestmentLot;
use App\Models\InvestmentTerm;
use App\Models\InvestmentTransaction;
use App\Models\User;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

class DividendCapitalizationService
{
    public function __construct(
        private readonly AvailableBalanceService $balances,
        private readonly FeeCalculatorService $fees,
        private readonly AccrualCalculator $decimal,
        private readonly AuditLogService $audit,
    ) {
    }

    public function capitalize(
        InvestmentAccount $account,
        string $amount,
        ?User $actor = null,
    ): DividendCapitalization {
        return DB::transaction(function () use ($account, $amount, $actor) {
            $account = InvestmentAccount::query()
                ->with('investor.user')
                ->lockForUpdate()
                ->findOrFail($account->id);

            $this->assertAccountAndActor($account, $actor);

            if ($this->decimal->compare($amount, '0') <= 0) {
                throw new DomainException('Capitalization amount must be greater than zero.');
            }

            $amount = $this->decimal->add($amount, '0');

            // Lock every component of the dividend balance before checking it again.
            $account->dailyAccruals()->lockForUpdate()->get();
            $account->withdrawalRequests()->where('type', 'dividend')->lockForUpdate()->get();
            $account->dividendCapitalizations()->lockForUpdate()->get();

            if ($this->decimal->compare($amount, $this->balances->availableDividendBalance($account)) > 0) {
                throw new DomainException('Capitalization amount exceeds available dividends.');
            }

            $capitalizedAt = Carbon::now();
            $date = $capitalizedAt->copy()->startOfDay();
            $term = $this->accountTerm($account, $date);

            if ($term === null) {
                throw new DomainException('An active account investment term is required for capitalization.');
            }

            $fee = $this->fees->calculate(
                'capitalization', $account->investor, $amount, $account->currency, $date,
            );
            $capitalizedAmount = $fee['payer'] === 'company' ? $amount : $fee['net_amount'];

            if ($this->decimal->compare($capitalizedAmount, '0') <= 0) {
                throw new DomainException('Capitalized amount must be greater than zero after fees.');
            }

            $accrualStartDate = $date->copy()->addDay();
            $unlockDate = $term->lock_months > 0
                ? $date->copy()->addMonthsNoOverflow($term->lock_months)
                : null;

            $capitalization = DividendCapitalization::create([
                'investor_id' => $account->investor_id,
                'investment_account_id' => $account->id,
                'requested_amount' => $amount,
                'fee_rule_id' => $fee['fee_rule_id'],
                'fee_amount' => $fee['fee_amount'],
                'fee_payer' => $fee['payer'],
                'fee_economic_type_snapshot' => $fee['economic_type'],
                'capitalized_amount' => $capitalizedAmount,
                'currency' => $account->currency,
                'status' => 'completed',
                'created_by' => $actor?->id,
                'capitalized_at' => $capitalizedAt,
            ]);

            $lot = InvestmentLot::create([
                'investment_account_id' => $account->id,
                'original_amount' => $capitalizedAmount,
                'remaining_amount' => $capitalizedAmount,
                'currency' => $account->currency,
                'received_at' => $capitalizedAt,
                'accrual_start_date' => $accrualStartDate,
                'lock_months' => $term->lock_months,
                'unlock_date' => $unlockDate,
                'monthly_rate' => $term->monthly_rate,
                'status' => 'active',
            ]);

            $transaction = InvestmentTransaction::create([
                'investment_account_id' => $account->id,
                'investment_lot_id' => $lot->id,
                'type' => 'capitalization',
                'amount' => $capitalizedAmount,
                'currency' => $account->currency,
                'effective_date' => $date,
                'status' => 'confirmed',
                'comment' => 'Dividend capitalization #'.$capitalization->id,
                'created_by' => $actor?->id,
                'confirmed_by' => $actor?->id,
                'confirmed_at' => $capitalizedAt,
            ]);

            $capitalization->update([
                'investment_lot_id' => $lot->id,
                'investment_transaction_id' => $transaction->id,
            ]);

            $this->audit->log('dividend_capitalization', $capitalization, $actor, null, [
                'requested_amount' => $amount,
                'fee' => $fee['fee_amount'],
                'capitalized_amount' => $capitalizedAmount,
                'investment_lot_id' => $lot->id,
                'investment_transaction_id' => $transaction->id,
            ]);

            return $capitalization->fresh(['investmentLot', 'investmentTransaction']);
        });
    }

    private function assertAccountAndActor(InvestmentAccount $account, ?User $actor): void
    {
        if ($account->status !== 'active') {
            throw new DomainException('Only an active investment account can be capitalized.');
        }

        if ($account->investor->status !== 'active' || ! $account->investor->user->is_active) {
            throw new DomainException('Only an active investor can capitalize dividends.');
        }

        if ($actor === null) {
            return;
        }

        if (! $actor->is_active || ! in_array($actor->role, ['admin', 'investor'], true)) {
            throw new DomainException('Actor is not allowed to capitalize dividends.');
        }

        if ($actor->role === 'investor' && $actor->investor?->id !== $account->investor_id) {
            throw new DomainException('Investor does not own this investment account.');
        }
    }

    private function accountTerm(InvestmentAccount $account, Carbon $date): ?InvestmentTerm
    {
        return $account->investmentTerms()
            ->whereNull('investment_lot_id')
            ->whereDate('valid_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date))
            ->latest('valid_from')
            ->latest('id')
            ->lockForUpdate()
            ->first();
    }
}
