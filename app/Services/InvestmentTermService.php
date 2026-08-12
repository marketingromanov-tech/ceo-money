<?php

namespace App\Services;

use App\Models\InvestmentAccount;
use App\Models\InvestmentTerm;
use App\Models\User;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

class InvestmentTermService
{
    public function __construct(private readonly AuditLogService $audit)
    {
    }

    public function createAccountTerm(
        InvestmentAccount $account,
        array $conditions,
        User $admin,
    ): InvestmentTerm {
        if ($admin->role !== 'admin' || ! $admin->is_active) {
            throw new DomainException('Only an active administrator may change investment terms.');
        }

        return DB::transaction(function () use ($account, $conditions, $admin) {
            $account = InvestmentAccount::query()->lockForUpdate()->findOrFail($account->id);
            $validFrom = Carbon::parse($conditions['valid_from'])->startOfDay();
            $terms = $account->investmentTerms()
                ->whereNull('investment_lot_id')
                ->lockForUpdate()
                ->orderBy('valid_from')
                ->orderBy('id')
                ->get();

            if ($terms->contains(fn (InvestmentTerm $term) => $term->valid_from->isSameDay($validFrom))) {
                throw new DomainException('An investment term already starts on this date.');
            }

            $previous = $terms->filter(fn (InvestmentTerm $term) => $term->valid_from->lt($validFrom))->last();
            $next = $terms->first(fn (InvestmentTerm $term) => $term->valid_from->gt($validFrom));
            $oldValues = $previous?->only([
                'id', 'monthly_rate', 'lock_months', 'minimum_balance',
                'partial_withdrawal_allowed', 'minimum_dividend_withdrawal', 'valid_from', 'valid_to',
            ]);

            if ($previous !== null && ($previous->valid_to === null || $previous->valid_to->gte($validFrom))) {
                $previous->update(['valid_to' => $validFrom->copy()->subDay()]);
            }

            $term = $account->investmentTerms()->create([
                'investment_lot_id' => null,
                'monthly_rate' => $conditions['monthly_rate'],
                'lock_months' => $conditions['lock_months'],
                'minimum_balance' => $conditions['minimum_balance'],
                'partial_withdrawal_allowed' => $conditions['partial_withdrawal_allowed'],
                'minimum_dividend_withdrawal' => $conditions['minimum_dividend_withdrawal'],
                'valid_from' => $validFrom,
                'valid_to' => $next?->valid_from->copy()->subDay(),
                'created_by' => $admin->id,
            ]);

            $this->audit->log('investment_term_created', $term, $admin, $oldValues, [
                ...$term->only([
                    'id', 'investment_account_id', 'monthly_rate', 'lock_months', 'minimum_balance',
                    'partial_withdrawal_allowed', 'minimum_dividend_withdrawal', 'valid_from', 'valid_to',
                ]),
                'investor_id' => $account->investor_id,
            ]);

            return $term;
        });
    }
}
