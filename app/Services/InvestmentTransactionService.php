<?php

namespace App\Services;

use App\Models\InvestmentTransaction;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class InvestmentTransactionService
{
    public function __construct(private readonly AuditLogService $audit)
    {
    }

    public function reverse(InvestmentTransaction $transaction, User $admin, string $reason): InvestmentTransaction
    {
        return DB::transaction(function () use ($transaction, $admin, $reason) {
            $transaction = InvestmentTransaction::query()->lockForUpdate()->findOrFail($transaction->id);

            if ($transaction->status !== 'confirmed' || $transaction->type === 'reversal') {
                throw new DomainException('Only a confirmed original transaction can be reversed.');
            }

            if ($transaction->reversals()->exists()) {
                throw new DomainException('Transaction has already been reversed.');
            }

            $reversal = InvestmentTransaction::create([
                'investment_account_id' => $transaction->investment_account_id,
                'investment_lot_id' => $transaction->investment_lot_id,
                'type' => 'reversal',
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
                'effective_date' => now()->toDateString(),
                'status' => 'confirmed',
                'comment' => $reason,
                'created_by' => $admin->id,
                'confirmed_by' => $admin->id,
                'confirmed_at' => now(),
                'reversal_of_id' => $transaction->id,
            ]);
            $this->audit->log('investment_transaction.reversed', $transaction, $admin, null, [
                'reversal_id' => $reversal->id, 'reason' => $reason,
            ]);

            return $reversal;
        });
    }
}
