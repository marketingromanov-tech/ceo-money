<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestorInvestmentTermVersion extends Model
{
    protected $fillable = [
        'investor_investment_term_id', 'currency', 'min_amount', 'max_amount', 'monthly_rate',
        'term_months', 'lock_days', 'partial_withdrawal', 'valid_from', 'valid_to',
        'created_by_admin_id', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'min_amount' => 'decimal:8', 'max_amount' => 'decimal:8', 'monthly_rate' => 'decimal:4',
            'term_months' => 'integer', 'lock_days' => 'integer', 'partial_withdrawal' => 'boolean',
            'valid_from' => 'date', 'valid_to' => 'date',
        ];
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(InvestorInvestmentTerm::class, 'investor_investment_term_id');
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin_id');
    }

    public function scopeValidForDate(Builder $query, CarbonInterface|string $date): Builder
    {
        $date = $date instanceof CarbonInterface ? $date->toDateString() : $date;

        return $query->whereDate('valid_from', '<=', $date)
            ->where(fn (Builder $query) => $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date));
    }
}
