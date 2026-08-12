<?php

namespace App\Console\Commands;

use App\Models\BackupRecord;
use App\Services\DatabaseRestoreTestService;
use Illuminate\Console\Command;

class BackupRestoreTest extends Command
{
    protected $signature = 'backup:restore-test {backup : Backup record ID} {--force : Confirm use in production/non-interactive mode} {--keep : Keep the isolated test database}';
    protected $description = 'Restore a backup into the configured isolated test database and validate it';

    public function handle(DatabaseRestoreTestService $service): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Production restore-test requires --force and a dedicated test database.');
            return self::FAILURE;
        }
        $backup = BackupRecord::find($this->argument('backup'));
        if (! $backup) {
            $this->error('Backup record not found.');
            return self::FAILURE;
        }
        try {
            $report = $service->run($backup, (bool) $this->option('keep'));
            $this->info('Isolated restore test completed successfully.');
            $this->table(['Check', 'Result'], [
                ['Database', $report['database']],
                ['Migrations', $report['migrations']],
                ['MFA ciphertexts', $report['mfa_ciphertexts']],
                ['Duplicate source references', $report['duplicate_source_references']],
            ]);
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
