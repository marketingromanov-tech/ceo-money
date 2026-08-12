<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestmentProgramVersion extends Model
{
    protected $fillable = ['investment_program_id', 'monthly_rate', 'lock_months', 'valid_from', 'valid_to', 'created_by'];

    protected function casts(): array
    {
        return ['monthly_rate' => 'decimal:4', 'lock_months' => 'integer', 'valid_from' => 'date', 'valid_to' => 'date'];
    }

    public function scopeActiveOn(Builder $query, Carbon $date): Builder
    {
        return $query->whereDate('valid_from', '<=', $date)->where(fn (Builder $q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date));
    }

    public function program(): BelongsTo { return $this->belongsTo(InvestmentProgram::class, 'investment_program_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
