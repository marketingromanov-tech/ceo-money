<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DepositAddress extends Model
{
    use HasFactory;

    protected $fillable = [
        'investor_id', 'provider', 'currency', 'network', 'address', 'label',
        'is_personal', 'is_active', 'assigned_at', 'archived_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_personal' => 'boolean',
            'is_active' => 'boolean',
            'assigned_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function investor(): BelongsTo { return $this->belongsTo(Investor::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function depositRequests(): HasMany { return $this->hasMany(DepositRequest::class); }
}
