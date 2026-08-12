<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvestmentProgram extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'status', 'currency', 'min_amount', 'max_amount', 'is_partial_withdrawal_allowed', 'created_by'];

    protected function casts(): array
    {
        return ['min_amount' => 'decimal:8', 'max_amount' => 'decimal:8', 'is_partial_withdrawal_allowed' => 'boolean'];
    }

    public function versions(): HasMany { return $this->hasMany(InvestmentProgramVersion::class); }
    public function lots(): HasMany { return $this->hasMany(InvestmentLot::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
