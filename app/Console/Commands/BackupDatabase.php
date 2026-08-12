<?php
namespace App\Console\Commands;
use App\Services\DatabaseBackupService;use Illuminate\Console\Command;
class BackupDatabase extends Command{protected $signature='backup:database {--verify}';protected $description='Create a private compressed MySQL database backup';public function handle(DatabaseBackupService $service):int{try{$r=$service->create(null,(bool)$this->option('verify'));if(!$this->option('quiet')){$this->table(['ID','Filename','Bytes','SHA-256','Status'],[[$r->id,$r->filename,$r->size_bytes,$r->sha256,$r->status]]);}return self::SUCCESS;}catch(\Throwable $e){$this->error($e->getMessage());return self::FAILURE;}}}
