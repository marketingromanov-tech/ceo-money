<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvestmentAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'investor_id',
        'currency',
        'status',
        'opened_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    public function investmentLots(): HasMany
    {
        return $this->hasMany(InvestmentLot::class);
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

    public function depositRequests(): HasMany
    {
        return $this->hasMany(DepositRequest::class);
    }

    public function withdrawalRequests(): HasMany
    {
        return $this->hasMany(WithdrawalRequest::class);
    }

    public function dividendPayments(): HasMany
    {
        return $this->hasMany(DividendPayment::class);
    }

    public function dividendCapitalizations(): HasMany
    {
        return $this->hasMany(DividendCapitalization::class);
    }
}
