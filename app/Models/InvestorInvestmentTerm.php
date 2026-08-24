<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvestorInvestmentTerm extends Model
{
    protected static function booted(): void
    {
        static::created(function (InvestorInvestmentTerm $term): void {
            if (! $term->versions()->exists()) {
                $term->versions()->create([
                    'currency' => $term->currency, 'min_amount' => $term->min_amount, 'max_amount' => $term->max_amount,
                    'monthly_rate' => $term->monthly_rate, 'term_months' => $term->term_months, 'lock_days' => $term->lock_days,
                    'partial_withdrawal' => $term->partial_withdrawal, 'valid_from' => $term->starts_at,
                    'valid_to' => $term->ends_at, 'created_by_admin_id' => $term->created_by_admin_id, 'notes' => $term->notes,
                ]);
            }
        });
    }

    protected $fillable = [
        'investor_id', 'currency', 'min_amount', 'max_amount', 'monthly_rate', 'term_months',
        'lock_days', 'partial_withdrawal', 'starts_at', 'ends_at', 'status', 'notes',
        'created_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'min_amount' => 'decimal:8',
            'max_amount' => 'decimal:8',
            'monthly_rate' => 'decimal:4',
            'term_months' => 'integer',
            'lock_days' => 'integer',
            'partial_withdrawal' => 'boolean',
            'starts_at' => 'date',
            'ends_at' => 'date',
        ];
    }

    public function investor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'investor_id');
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(InvestorInvestmentTermVersion::class)->orderByDesc('valid_from')->orderByDesc('id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeValidForDate(Builder $query, CarbonInterface|string $date): Builder
    {
        $date = $date instanceof CarbonInterface ? $date->toDateString() : $date;

        return $query->whereDate('starts_at', '<=', $date)
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhereDate('ends_at', '>=', $date));
    }
}
