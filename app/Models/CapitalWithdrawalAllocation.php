<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CapitalWithdrawalAllocation extends Model
{
    use HasFactory;

    protected $fillable = ['withdrawal_request_id', 'investment_lot_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:8'];
    }

    public function withdrawalRequest(): BelongsTo { return $this->belongsTo(WithdrawalRequest::class); }
    public function investmentLot(): BelongsTo { return $this->belongsTo(InvestmentLot::class); }
}
