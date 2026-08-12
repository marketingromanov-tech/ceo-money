<?php

namespace App\Services;

use App\Models\BackupRecord;
use DomainException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseRestoreTestService
{
    public function __construct(private readonly DatabaseBackupService $backups) {}

    /** @return array<string, mixed> */
    public function run(BackupRecord $backup, bool $keepDatabase = false): array
    {
        $this->backups->verify($backup);
        $database = (string) config('backup.restore_test_database');
        $source = (string) config('database.connections.mysql.database');

        if ($database === '' || ! preg_match('/^[A-Za-z0-9_]+$/', $database)) {
            throw new DomainException('BACKUP_RESTORE_TEST_DATABASE must contain a dedicated database name.');
        }
        if (hash_equals($source, $database)) {
            throw new DomainException('Restore-test database must not be the current application database.');
        }

        $this->recreateDatabase($database);

        try {
            $this->import($this->backups->absolutePath($backup), $database);
            $report = $this->inspect($database);
            app(AuditLogService::class)->log('backup.restore_test_completed', $backup, auth()->user(), null, [
                'backup_record_id' => $backup->id,
                'database_name' => $database,
                'table_counts' => $report['table_counts'],
            ]);

            return $report;
        } finally {
            DB::purge('backup_restore_test');
            if (! $keepDatabase) {
                $this->dropDatabase($database);
            }
        }
    }

    private function recreateDatabase(string $database): void
    {
        $this->runMysql(null, sprintf(
            'DROP DATABASE IF EXISTS `%s`; CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
            $database,
            $database,
        ));
    }

    private function dropDatabase(string $database): void
    {
        $this->runMysql(null, sprintf('DROP DATABASE IF EXISTS `%s`;', $database));
    }

    private function import(string $archive, string $database): void
    {
        $db = config('database.connections.mysql');
        $command = [
            config('backup.mysql_binary'),
            '--host='.$db['host'],
            '--port='.(string) $db['port'],
            '--user='.$db['username'],
            '--default-character-set=utf8mb4',
            $database,
        ];
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 2 => ['pipe', 'w']], $pipes, null, $this->environment((string) $db['password']));
        if (! is_resource($process)) {
            throw new DomainException('mysql client could not be started.');
        }

        $gzip = gzopen($archive, 'rb');
        if ($gzip === false) {
            proc_terminate($process);
            throw new DomainException('Backup gzip stream cannot be opened.');
        }
        $streamFailed = false;
        while (! gzeof($gzip)) {
            $chunk = gzread($gzip, 1048576);
            if ($chunk === false || @fwrite($pipes[0], $chunk) === false) {
                $streamFailed = true;
                break;
            }
        }
        gzclose($gzip);
        @fclose($pipes[0]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || $streamFailed) {
            throw new DomainException('Restore test import failed: '.mb_substr(trim($error), 0, 500));
        }
    }

    /** @return array<string, mixed> */
    private function inspect(string $database): array
    {
        $connection = config('database.connections.mysql');
        $connection['database'] = $database;
        Config::set('database.connections.backup_restore_test', $connection);
        DB::purge('backup_restore_test');

        $schema = Schema::connection('backup_restore_test');
        foreach (['migrations', 'users', 'investors', 'investment_accounts', 'investment_lots', 'investment_transactions', 'deposit_requests', 'withdrawal_requests'] as $table) {
            if (! $schema->hasTable($table)) {
                throw new DomainException("Restore test is missing required table: {$table}.");
            }
        }

        $counts = [];
        foreach (['users', 'investors', 'investment_accounts', 'investment_lots', 'investment_transactions', 'deposit_requests', 'withdrawal_requests', 'daily_accruals'] as $table) {
            $counts[$table] = $schema->hasTable($table) ? DB::connection('backup_restore_test')->table($table)->count() : 0;
        }

        $duplicateLots = DB::connection('backup_restore_test')->table('investment_lots')
            ->whereNotNull('deposit_request_id')->groupBy('deposit_request_id')->havingRaw('COUNT(*) > 1')->count();
        $duplicateDepositTransactions = DB::connection('backup_restore_test')->table('investment_transactions')
            ->whereNotNull('deposit_request_id')->groupBy('deposit_request_id')->havingRaw('COUNT(*) > 1')->count();
        $duplicateWithdrawalTransactions = DB::connection('backup_restore_test')->table('investment_transactions')
            ->whereNotNull('withdrawal_request_id')->groupBy('withdrawal_request_id')->havingRaw('COUNT(*) > 1')->count();
        if ($duplicateLots + $duplicateDepositTransactions + $duplicateWithdrawalTransactions > 0) {
            throw new DomainException('Restore test found duplicate source references in financial records.');
        }

        $mfaCiphertexts = DB::connection('backup_restore_test')->table('users')->whereNotNull('mfa_secret')->pluck('mfa_secret');
        if ($mfaCiphertexts->contains(fn ($value) => ! is_string($value) || $value === '')) {
            throw new DomainException('Restore test found an invalid MFA ciphertext.');
        }

        return [
            'database' => $database,
            'table_counts' => $counts,
            'migrations' => DB::connection('backup_restore_test')->table('migrations')->count(),
            'mfa_ciphertexts' => $mfaCiphertexts->count(),
            'duplicate_source_references' => 0,
        ];
    }

    private function runMysql(?string $database, string $statement): void
    {
        $db = config('database.connections.mysql');
        $command = [config('backup.mysql_binary'), '--host='.$db['host'], '--port='.(string) $db['port'], '--user='.$db['username']];
        if ($database !== null) {
            $command[] = $database;
        }
        $command[] = '--execute='.$statement;
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $this->environment((string) $db['password']));
        if (! is_resource($process)) {
            throw new DomainException('mysql client could not be started.');
        }
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new DomainException('Restore test database preparation failed: '.mb_substr(trim($error), 0, 500));
        }
    }

    private function environment(string $password): array
    {
        $environment = getenv();
        return array_merge(is_array($environment) ? $environment : [], ['MYSQL_PWD' => $password]);
    }
}
