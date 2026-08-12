<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InvestmentTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'investment_account_id',
        'investment_lot_id',
        'deposit_request_id',
        'withdrawal_request_id',
        'type',
        'amount',
        'currency',
        'effective_date',
        'status',
        'comment',
        'created_by',
        'confirmed_by',
        'confirmed_at',
        'reversal_of_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:8',
            'effective_date' => 'date',
            'confirmed_at' => 'datetime',
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

    public function depositRequest(): BelongsTo
    {
        return $this->belongsTo(DepositRequest::class);
    }

    public function withdrawalRequest(): BelongsTo
    {
        return $this->belongsTo(WithdrawalRequest::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reversal_of_id');
    }

    public function dividendCapitalization(): HasOne
    {
        return $this->hasOne(DividendCapitalization::class);
    }
}
