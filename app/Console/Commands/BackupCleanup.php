<?php
namespace App\Console\Commands;
use App\Services\DatabaseBackupService;use Illuminate\Console\Command;
class BackupCleanup extends Command{protected $signature='backup:cleanup';protected $description='Delete application backups older than configured retention';public function handle(DatabaseBackupService $service):int{$this->info($service->cleanup().' backup(s) deleted.');return self::SUCCESS;}}
