<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    public const STATUSES=['new','open','waiting_investor','resolved','closed'];
    public const PRIORITIES=['normal','high','urgent'];
    public const INVESTOR_PRIORITIES=['normal','high'];
    public const CATEGORIES=['general','finance','accruals','deposit','withdrawal','wallet','technical','other'];
    public const RELATED_TYPES=['withdrawal_request','deposit_request','investor_wallet'];
    protected $fillable=['investor_id','created_by_user_id','assigned_to_user_id','category','subject','status','priority','related_type','related_id','investor_last_read_at','admin_last_read_at','last_message_at','resolved_at','closed_at'];
    protected function casts():array{return['investor_last_read_at'=>'datetime','admin_last_read_at'=>'datetime','last_message_at'=>'datetime','resolved_at'=>'datetime','closed_at'=>'datetime'];}
    public function investor():BelongsTo{return $this->belongsTo(Investor::class);}
    public function creator():BelongsTo{return $this->belongsTo(User::class,'created_by_user_id');}
    public function assignee():BelongsTo{return $this->belongsTo(User::class,'assigned_to_user_id');}
    public function messages():HasMany{return $this->hasMany(SupportMessage::class);}
}
