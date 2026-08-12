<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DividendPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'withdrawal_request_id', 'investment_account_id', 'gross_amount', 'fee_amount',
        'net_amount', 'currency', 'wallet_address', 'network', 'txid', 'paid_at', 'paid_by',
    ];

    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:8',
            'fee_amount' => 'decimal:8',
            'net_amount' => 'decimal:8',
            'paid_at' => 'datetime',
        ];
    }

    public function withdrawalRequest(): BelongsTo { return $this->belongsTo(WithdrawalRequest::class); }
    public function investmentAccount(): BelongsTo { return $this->belongsTo(InvestmentAccount::class); }
    public function paidBy(): BelongsTo { return $this->belongsTo(User::class, 'paid_by'); }
}
