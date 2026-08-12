<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Investor extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'code',
        'phone',
        'contract_number',
        'contract_date',
        'status',
        'admin_note',
    ];

    protected function casts(): array
    {
        return [
            'contract_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function investmentAccounts(): HasMany
    {
        return $this->hasMany(InvestmentAccount::class);
    }

    public function feeRules(): HasMany
    {
        return $this->hasMany(FeeRule::class);
    }

    public function depositRequests(): HasMany
    {
        return $this->hasMany(DepositRequest::class);
    }

    public function withdrawalRequests(): HasMany
    {
        return $this->hasMany(WithdrawalRequest::class);
    }

    public function depositAddresses(): HasMany
    {
        return $this->hasMany(DepositAddress::class);
    }

    public function paymentDetails(): HasMany
    {
        return $this->hasMany(InvestorPaymentDetail::class);
    }

    public function withdrawalDetails(): HasMany
    {
        return $this->hasMany(InvestorWithdrawalDetail::class);
    }

    public function wallets(): HasMany
    {
        return $this->hasMany(InvestorWallet::class);
    }

    public function dividendCapitalizations(): HasMany
    {
        return $this->hasMany(DividendCapitalization::class);
    }
}
