<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvestorWallet extends Model
{
    use HasFactory;

    public const STATUSES = ['pending', 'approved', 'blocked', 'archived'];

    protected $fillable = [
        'investor_id', 'currency', 'network', 'address', 'label', 'status',
        'approved_by', 'approved_at', 'blocked_at', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'blocked_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function investor(): BelongsTo { return $this->belongsTo(Investor::class); }
    public function approvedBy(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function withdrawalRequests(): HasMany { return $this->hasMany(WithdrawalRequest::class); }
}
