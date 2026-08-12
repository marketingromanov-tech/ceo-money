<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DepositRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'investor_id', 'investment_account_id', 'investment_program_id', 'investment_program_snapshot', 'payment_details_snapshot', 'requested_amount', 'received_amount',
        'currency', 'network', 'deposit_address_id', 'deposit_address_snapshot',
        'provider_snapshot', 'txid', 'fee_rule_id', 'fee_amount', 'fee_payer', 'fee_economic_type_snapshot',
        'net_investment_amount', 'status', 'requested_at', 'submitted_at', 'submitted_by',
        'confirmed_at', 'confirmed_by', 'rejected_at', 'rejected_by', 'rejected_reason',
        'cancelled_at', 'cancelled_by', 'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'requested_amount' => 'decimal:8',
            'received_amount' => 'decimal:8',
            'fee_amount' => 'decimal:8',
            'net_investment_amount' => 'decimal:8',
            'investment_program_snapshot' => 'array',
            'payment_details_snapshot' => 'array',
            'requested_at' => 'datetime',
            'submitted_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function investor(): BelongsTo { return $this->belongsTo(Investor::class); }
    public function investmentAccount(): BelongsTo { return $this->belongsTo(InvestmentAccount::class); }
    public function investmentProgram(): BelongsTo { return $this->belongsTo(InvestmentProgram::class); }
    public function feeRule(): BelongsTo { return $this->belongsTo(FeeRule::class); }
    public function confirmer(): BelongsTo { return $this->belongsTo(User::class, 'confirmed_by'); }
    public function submitter(): BelongsTo { return $this->belongsTo(User::class, 'submitted_by'); }
    public function rejector(): BelongsTo { return $this->belongsTo(User::class, 'rejected_by'); }
    public function canceller(): BelongsTo { return $this->belongsTo(User::class, 'cancelled_by'); }
    public function depositAddress(): BelongsTo { return $this->belongsTo(DepositAddress::class); }
    public function verificationChecks(): HasMany { return $this->hasMany(DepositVerificationCheck::class); }
    public function investmentLot(): HasOne { return $this->hasOne(InvestmentLot::class); }
    public function investmentTransaction(): HasOne { return $this->hasOne(InvestmentTransaction::class); }
}
