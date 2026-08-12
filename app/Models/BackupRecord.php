<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class BackupRecord extends Model {
    public const CREATING='creating', COMPLETED='completed', VERIFIED='verified', FAILED='failed', DELETED='deleted';
    protected $fillable=['type','filename','storage_disk','storage_path','size_bytes','sha256','status','created_by','started_at','completed_at','verified_at','failure_message','database_name','application_commit'];
    protected function casts():array{return ['size_bytes'=>'integer','started_at'=>'datetime','completed_at'=>'datetime','verified_at'=>'datetime'];}
    public function creator():BelongsTo{return $this->belongsTo(User::class,'created_by');}
}
