<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WithdrawalRequest extends Model
{
    use HasFactory;

    public const ACTIVE_RESERVATION_STATUSES = ['new', 'review', 'approved'];

    protected $fillable = [
        'investor_id', 'investment_account_id', 'type', 'requested_amount',
        'reserved_amount', 'fee_rule_id', 'fee_amount', 'fee_payer', 'fee_economic_type_snapshot', 'net_amount',
        'currency', 'investor_wallet_id', 'wallet_address_snapshot', 'network_snapshot', 'withdrawal_memo_snapshot', 'status', 'txid',
        'requested_at', 'approved_at', 'approved_by', 'paid_at', 'paid_by',
        'cancelled_at', 'cancelled_by', 'cancellation_reason', 'rejected_at', 'rejected_by', 'rejected_reason',
    ];

    protected function casts(): array
    {
        return [
            'requested_amount' => 'decimal:8',
            'reserved_amount' => 'decimal:8',
            'fee_amount' => 'decimal:8',
            'net_amount' => 'decimal:8',
            'requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function investor(): BelongsTo { return $this->belongsTo(Investor::class); }
    public function investmentAccount(): BelongsTo { return $this->belongsTo(InvestmentAccount::class); }
    public function feeRule(): BelongsTo { return $this->belongsTo(FeeRule::class); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function payerUser(): BelongsTo { return $this->belongsTo(User::class, 'paid_by'); }
    public function canceller(): BelongsTo { return $this->belongsTo(User::class, 'cancelled_by'); }
    public function rejector(): BelongsTo { return $this->belongsTo(User::class, 'rejected_by'); }
    public function dividendPayment(): HasOne { return $this->hasOne(DividendPayment::class); }
    public function capitalWithdrawalAllocations(): HasMany { return $this->hasMany(CapitalWithdrawalAllocation::class); }
    public function investorWallet(): BelongsTo { return $this->belongsTo(InvestorWallet::class); }
    public function verificationChecks(): HasMany { return $this->hasMany(WithdrawalVerificationCheck::class); }
    public function investmentTransaction(): HasOne { return $this->hasOne(InvestmentTransaction::class); }
}
