<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeeRule extends Model
{
    use HasFactory;

    public const OPERATION_TYPES = [
        'deposit',
        'capital_withdrawal',
        'dividend_withdrawal',
        'capitalization',
        'manual_adjustment',
    ];

    public const FEE_TYPES = ['fixed', 'percent', 'mixed'];

    public const PAYERS = ['investor', 'company'];

    public const ECONOMIC_TYPES = ['platform_fee', 'provider_cost'];

    public const SCOPES = ['global', 'investor'];

    protected $fillable = [
        'operation_type',
        'scope',
        'investor_id',
        'currency',
        'fee_type',
        'percent_value',
        'fixed_value',
        'payer',
        'economic_type',
        'minimum_fee',
        'maximum_fee',
        'valid_from',
        'valid_to',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'percent_value' => 'decimal:4',
            'fixed_value' => 'decimal:8',
            'minimum_fee' => 'decimal:8',
            'maximum_fee' => 'decimal:8',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function investor(): BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function depositRequests(): HasMany
    {
        return $this->hasMany(DepositRequest::class);
    }

    public function withdrawalRequests(): HasMany
    {
        return $this->hasMany(WithdrawalRequest::class);
    }

    public function dividendCapitalizations(): HasMany
    {
        return $this->hasMany(DividendCapitalization::class);
    }
}
