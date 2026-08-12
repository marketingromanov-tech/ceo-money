<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyAccrual extends Model
{
    use HasFactory;

    protected $fillable = [
        'investment_account_id',
        'investment_lot_id',
        'accrual_date',
        'principal_amount',
        'monthly_rate',
        'days_in_month',
        'calculated_amount',
        'adjustment_amount',
        'final_amount',
        'status',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'accrual_date' => 'date',
            'principal_amount' => 'decimal:8',
            'monthly_rate' => 'decimal:4',
            'days_in_month' => 'integer',
            'calculated_amount' => 'decimal:8',
            'adjustment_amount' => 'decimal:8',
            'final_amount' => 'decimal:8',
            'calculated_at' => 'datetime',
        ];
    }

    public function investmentAccount(): BelongsTo
    {
        return $this->belongsTo(InvestmentAccount::class);
    }

    public function investmentLot(): BelongsTo
    {
        return $this->belongsTo(InvestmentLot::class);
    }
}
