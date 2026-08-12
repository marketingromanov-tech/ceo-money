<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InvestmentLot extends Model
{
    use HasFactory;

    protected $fillable = [
        'investment_account_id',
        'deposit_request_id',
        'investment_program_id',
        'investment_program_name_snapshot',
        'investment_program_version_snapshot',
        'original_amount',
        'remaining_amount',
        'currency',
        'received_at',
        'accrual_start_date',
        'lock_months',
        'unlock_date',
        'monthly_rate',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'original_amount' => 'decimal:8',
            'remaining_amount' => 'decimal:8',
            'received_at' => 'datetime',
            'accrual_start_date' => 'date',
            'unlock_date' => 'date',
            'lock_months' => 'integer',
            'monthly_rate' => 'decimal:4',
        ];
    }

    public function investmentAccount(): BelongsTo
    {
        return $this->belongsTo(InvestmentAccount::class);
    }

    public function depositRequest(): BelongsTo
    {
        return $this->belongsTo(DepositRequest::class);
    }

    public function investmentProgram(): BelongsTo
    {
        return $this->belongsTo(InvestmentProgram::class);
    }

    public function investmentTransactions(): HasMany
    {
        return $this->hasMany(InvestmentTransaction::class);
    }

    public function investmentTerms(): HasMany
    {
        return $this->hasMany(InvestmentTerm::class);
    }

    public function dailyAccruals(): HasMany
    {
        return $this->hasMany(DailyAccrual::class);
    }

    public function accrualPauses(): HasMany
    {
        return $this->hasMany(AccrualPause::class);
    }

    public function capitalWithdrawalAllocations(): HasMany
    {
        return $this->hasMany(CapitalWithdrawalAllocation::class);
    }

    public function dividendCapitalization(): HasOne
    {
        return $this->hasOne(DividendCapitalization::class);
    }
}
