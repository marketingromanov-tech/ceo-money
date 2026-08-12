<?php
namespace App\Services;

use App\Models\BackupRecord;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class DatabaseBackupService
{
    public function create(?User $actor=null,bool $verify=false):BackupRecord
    {
        $name='ceo-money-db-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(2)).'.sql.gz';
        $path=config('backup.directory').'/'.$name; $tmp=$path.'.tmp'; $disk=Storage::disk(config('backup.disk'));
        $record=BackupRecord::create(['filename'=>$name,'storage_disk'=>config('backup.disk'),'storage_path'=>$path,'status'=>BackupRecord::CREATING,'created_by'=>$actor?->id,'started_at'=>now(),'database_name'=>config('database.connections.mysql.database'),'application_commit'=>$this->commit()]);
        try {
            File::ensureDirectoryExists(dirname($disk->path($tmp)),0700,true);
            $this->dump($disk->path($tmp));
            if(!rename($disk->path($tmp),$disk->path($path))) throw new DomainException('Unable to finalize backup file.');
            $record->update(['size_bytes'=>filesize($disk->path($path)),'sha256'=>hash_file('sha256',$disk->path($path)),'status'=>BackupRecord::COMPLETED,'completed_at'=>now()]);
            if($verify)$this->verify($record->fresh());
            app(AuditLogService::class)->log('backup.database_created',$record,$actor,null,['backup_record_id'=>$record->id,'filename'=>$name,'size_bytes'=>$record->size_bytes,'sha256'=>$record->sha256]);
            return $record->fresh();
        } catch(\Throwable $e) {
            if($disk->exists($tmp))$disk->delete($tmp);
            $record->update(['status'=>BackupRecord::FAILED,'failure_message'=>mb_substr($e->getMessage(),0,2000)]);
            throw $e;
        }
    }

    public function verify(BackupRecord $record):BackupRecord
    {
        $absolute=$this->absolute($record); if(!is_file($absolute))throw new DomainException('Backup file does not exist.');
        if(filesize($absolute)!==$record->size_bytes)throw new DomainException('Backup file size mismatch.');
        if(!hash_equals((string)$record->sha256,hash_file('sha256',$absolute)))throw new DomainException('Backup checksum mismatch.');
        $gz=gzopen($absolute,'rb'); if(!$gz)throw new DomainException('Backup gzip stream cannot be opened.');
        $sample='';
        while (! gzeof($gz)) {
            $chunk = gzread($gz, 1048576);
            if ($chunk === false) {
                gzclose($gz);
                throw new DomainException('Backup gzip stream is corrupted.');
            }
            if (strlen($sample) < 262144) {
                $sample .= substr($chunk, 0, 262144 - strlen($sample));
            }
        }
        gzclose($gz);
        if(strlen(trim($sample))<100||(!str_contains($sample,'CREATE TABLE')&&!str_contains($sample,'MySQL dump')))throw new DomainException('Backup SQL structure is not plausible.');
        $record->update(['status'=>BackupRecord::VERIFIED,'verified_at'=>now(),'failure_message'=>null]);
        app(AuditLogService::class)->log('backup.database_verified',$record,auth()->user(),null,['backup_record_id'=>$record->id,'sha256'=>$record->sha256]);
        return $record->fresh();
    }

    public function delete(BackupRecord $record,?User $actor=null):void
    {
        $absolute=$this->absolute($record); if(is_file($absolute)&&!unlink($absolute))throw new DomainException('Backup file could not be deleted.');
        $record->update(['status'=>BackupRecord::DELETED]); app(AuditLogService::class)->log('backup.database_deleted',$record,$actor,null,['backup_record_id'=>$record->id,'filename'=>$record->filename]);
    }

    public function cleanup(?User $actor=null):int
    {
        $cutoff=now()->subDays(config('backup.retention_days'));$items=BackupRecord::whereIn('status',[BackupRecord::COMPLETED,BackupRecord::VERIFIED])->where('created_at','<',$cutoff)->get();
        foreach($items as $item)$this->delete($item,$actor); return $items->count();
    }

    public function records(): Collection
    {
        return BackupRecord::query()->latest()->get();
    }

    public function absolutePath(BackupRecord $record): string
    {
        return $this->absolute($record);
    }

    private function dump(string $target):void
    {
        $db=config('database.connections.mysql');$command=[config('backup.mysqldump_binary'),'--host='.$db['host'],'--port='.(string)$db['port'],'--user='.$db['username'],'--single-transaction','--quick','--skip-lock-tables','--default-character-set=utf8mb4','--triggers','--routines',(string)$db['database']];
        $pipes=[];$process=proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$this->processEnvironment((string)$db['password']));if(!is_resource($process))throw new DomainException('mysqldump could not be started.');
        $gz=gzopen($target,'wb9');while(!feof($pipes[1]))gzwrite($gz,fread($pipes[1],1048576));gzclose($gz);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($process);
        if($code!==0)throw new DomainException('Database dump failed: '.mb_substr(trim($error),0,500));
    }
    private function processEnvironment(string $password): array
    {
        $environment = getenv();
        return array_merge(is_array($environment) ? $environment : [], ['MYSQL_PWD' => $password]);
    }

    private function absolute(BackupRecord $record):string
    {
        if($record->storage_disk!==config('backup.disk'))throw new DomainException('Unexpected backup disk.');
        $root=realpath(Storage::disk(config('backup.disk'))->path(''))?:Storage::disk(config('backup.disk'))->path('');$path=Storage::disk($record->storage_disk)->path($record->storage_path);$parent=realpath(dirname($path));
        if(!$parent||!str_starts_with($parent.DIRECTORY_SEPARATOR,rtrim($root,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR))throw new DomainException('Backup path is outside configured root.');return $path;
    }
    private function commit():?string{$value=(string)env('APP_COMMIT','');return preg_match('/^[a-f0-9]{7,40}$/',$value)?$value:null;}
}
