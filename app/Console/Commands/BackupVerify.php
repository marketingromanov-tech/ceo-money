<?php
namespace App\Console\Commands;
use App\Models\BackupRecord;use App\Services\DatabaseBackupService;use Illuminate\Console\Command;
class BackupVerify extends Command{protected $signature='backup:verify {backup}';protected $description='Verify backup file integrity without restoring it';public function handle(DatabaseBackupService $service):int{try{$r=$service->verify(BackupRecord::findOrFail((int)$this->argument('backup')));$this->info("File verification passed for backup #{$r->id}; this is not a restore verification.");return self::SUCCESS;}catch(\Throwable $e){$this->error($e->getMessage());return self::FAILURE;}}}
