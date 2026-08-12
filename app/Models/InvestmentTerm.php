<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestmentTerm extends Model
{
    use HasFactory;

    protected $fillable = [
        'investment_account_id',
        'investment_lot_id',
        'monthly_rate',
        'lock_months',
        'minimum_balance',
        'partial_withdrawal_allowed',
        'minimum_dividend_withdrawal',
        'valid_from',
        'valid_to',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'monthly_rate' => 'decimal:4',
            'lock_months' => 'integer',
            'minimum_balance' => 'decimal:8',
            'partial_withdrawal_allowed' => 'boolean',
            'minimum_dividend_withdrawal' => 'decimal:8',
            'valid_from' => 'date',
            'valid_to' => 'date',
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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
