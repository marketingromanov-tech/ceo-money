<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepositVerificationCheck extends Model
{
    protected $fillable = ['deposit_request_id', 'check_key', 'status', 'checked_by_user_id', 'checked_at', 'value_snapshot'];

    protected function casts(): array
    {
        return ['checked_at' => 'datetime', 'value_snapshot' => 'array'];
    }

    public function depositRequest(): BelongsTo { return $this->belongsTo(DepositRequest::class); }
    public function checkedBy(): BelongsTo { return $this->belongsTo(User::class, 'checked_by_user_id'); }
}
