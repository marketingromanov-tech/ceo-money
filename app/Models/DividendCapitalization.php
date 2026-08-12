<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DividendCapitalization extends Model
{
    use HasFactory;

    protected $fillable = [
        'investor_id',
        'investment_account_id',
        'requested_amount',
        'fee_rule_id',
        'fee_amount',
        'fee_payer',
        'fee_economic_type_snapshot',
        'capitalized_amount',
        'currency',
        'status',
        'investment_lot_id',
        'investment_transaction_id',
        'created_by',
        'capitalized_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_amount' => 'decimal:8',
            'fee_amount' => 'decimal:8',
            'capitalized_amount' => 'decimal:8',
            'capitalized_at' => 'datetime',
        ];
    }

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    public function investmentAccount(): BelongsTo
    {
        return $this->belongsTo(InvestmentAccount::class);
    }

    public function investmentLot(): BelongsTo
    {
        return $this->belongsTo(InvestmentLot::class);
    }

    public function investmentTransaction(): BelongsTo
    {
        return $this->belongsTo(InvestmentTransaction::class);
    }

    public function feeRule(): BelongsTo
    {
        return $this->belongsTo(FeeRule::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
