<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;

class ProductionCheck extends Command
{
    protected $signature = 'app:production-check';
    protected $description = 'Read-only validation of the CEO Money production baseline';

    public function handle(Migrator $migrator): int
    {
        $checks = [];
        $check = function (string $name, bool $passed, string $detail) use (&$checks): void {
            $checks[] = $passed;
            $this->{$passed ? 'info' : 'error'}(($passed ? '[OK] ' : '[BLOCK] ').$name.': '.$detail);
        };
        $warning = fn (string $name, string $detail) => $this->warn('[WARN] '.$name.': '.$detail);

        $production = app()->environment('production');
        $check('APP_ENV', $production, (string) app()->environment());
        $check('APP_DEBUG', ! config('app.debug'), config('app.debug') ? 'must be false' : 'false');
        $check('APP_URL', str_starts_with((string) config('app.url'), 'https://'), 'must use https://');
        $check('APP_KEY', filled(config('app.key')), filled(config('app.key')) ? 'configured' : 'missing');
        $commit = (string) config('app.commit', '');
        $check('APP_COMMIT', ! $production || preg_match('/^[a-f0-9]{7,40}$/', $commit) === 1, $production ? ($commit === '' ? 'missing' : 'format validated') : 'not required outside production');

        $trusted = config('security.trusted_proxies', []);
        $check('Trusted proxies wildcard', ! in_array('*', $trusted, true), 'wildcard must never be used');
        $check('Trusted proxies configured', ! $production || $trusted !== [], $production ? 'required in production' : 'not required outside production');
        $check('Security headers', config('security.headers_enabled') === true, config('security.headers_enabled') ? 'enabled' : 'disabled');
        $check('CSP Report-Only', config('security.csp_report_only') === true, config('security.csp_report_only') ? 'enabled' : 'must remain enabled for the current policy');

        $check('Session driver', config('session.driver') === 'database', (string) config('session.driver'));
        $check('Session encryption', config('session.encrypt') === true, config('session.encrypt') ? 'enabled' : 'disabled');
        $check('Secure cookie', config('session.secure') === true, config('session.secure') ? 'enabled' : 'disabled');
        try {
            $sessionTableExists = config('session.driver') !== 'database' || Schema::hasTable((string) config('session.table', 'sessions'));
            $check('Session table', $sessionTableExists, 'required for database sessions');
        } catch (\Throwable) {
            $check('Session table', false, 'status unavailable');
        }

        $driver = DB::connection()->getDriverName();
        $check('Database driver', $driver === 'mysql', $driver);
        try {
            DB::connection()->getPdo();
            $version = (string) DB::selectOne('select version() as version')->version;
            preg_match('/^(\d+\.\d+)/', $version, $match);
            $check('Database', true, 'MySQL '.($match[1] ?? 'version unavailable'));
        } catch (\Throwable) {
            $check('Database', false, 'connection or version check failed');
        }

        try {
            $files = array_keys($migrator->getMigrationFiles(database_path('migrations')));
            $pending = array_diff($files, $migrator->getRepository()->getRan());
            $check('Migrations', $pending === [], $pending === [] ? 'up to date' : count($pending).' pending');
        } catch (\Throwable) {
            $check('Migrations', false, 'status unavailable');
        }

        foreach ([storage_path('framework'), storage_path('logs'), base_path('bootstrap/cache')] as $path) {
            $check('Writable '.$path, is_dir($path) && is_writable($path), 'directory must be writable');
        }

        $backupRoot = Storage::disk(config('backup.disk'))->path('');
        $publicRoot = realpath(public_path()) ?: public_path();
        $resolvedBackupRoot = realpath($backupRoot) ?: $backupRoot;
        $outsidePublic = ! str_starts_with(rtrim($resolvedBackupRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR, rtrim($publicRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
        $check('Backup path privacy', $outsidePublic, 'must be outside public/');
        $check('Backup directory', is_dir($backupRoot), 'directory must exist');
        $check('Backup directory writable', is_dir($backupRoot) && is_writable($backupRoot), 'directory must be writable');
        $freeBytes = @disk_free_space(is_dir($backupRoot) ? $backupRoot : dirname($backupRoot));
        if ($freeBytes === false) {
            $warning('Backup free space', 'platform did not expose filesystem free space');
        } else {
            $check('Backup free space', $freeBytes >= (int) config('backup.min_free_bytes'), 'minimum threshold '.config('backup.min_free_bytes').' bytes');
        }
        $check('Backup retention', (int) config('backup.retention_days') > 0, 'must be greater than zero');
        $check('mysqldump client', $this->binaryAvailable((string) config('backup.mysqldump_binary')), 'binary must be executable');
        $check('mysql client', $this->binaryAvailable((string) config('backup.mysql_binary')), 'binary must be executable');

        $operational = config('backup.database', []);
        $operationalConfigured = collect(['host', 'username', 'password'])->every(fn ($key) => is_string($operational[$key] ?? null) && trim($operational[$key]) !== '');
        $check('Backup operational credentials', ! $production || $operationalConfigured, $operationalConfigured ? 'configured (values hidden)' : ($production ? 'missing' : 'not configured locally'));
        $operationalHost = trim((string) ($operational['host'] ?? ''));
        $operationalPort = filter_var($operational['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $operationalSafe = ! $operationalConfigured || ($operationalPort !== false
            && ! in_array(strtolower($operationalHost), ['*', '0.0.0.0', '::'], true)
            && ! preg_match('/[\s\/:\\\\]/', $operationalHost)
            && ! hash_equals(trim((string) config('database.connections.mysql.username')), trim((string) ($operational['username'] ?? ''))));
        $check('Backup operational isolation', ! $production || ($operationalConfigured && $operationalSafe), ! $operationalConfigured ? 'not configured outside production' : ($operationalSafe ? 'host/port and separate principal validated' : 'invalid or application principal reused'));
        $restoreDatabase = trim((string) config('backup.restore_test_database'));
        $check('Restore-test database', ! $production || ($restoreDatabase !== '' && strcasecmp(trim((string) config('database.connections.mysql.database'), " `"), trim($restoreDatabase, " `")) !== 0), $production ? 'must be dedicated and differ from application database' : 'not required outside production');

        $check('Frontend manifest', is_file((string) config('production.frontend_manifest')), 'public/build/manifest.json must exist in release');
        $check('Demo seeding protection', ! app()->environment(['local', 'testing']), 'DatabaseSeeder permits demo data only in local/testing');
        try {
            $withoutMfa = User::query()->where('role', 'admin')->where('is_active', true)->whereNull('mfa_enabled_at')->count();
            $check('Active admin MFA', $withoutMfa === 0, $withoutMfa.' active admins without MFA');
        } catch (\Throwable) {
            $check('Active admin MFA', false, 'unable to inspect');
        }

        $scheduledCommands = collect(app(Schedule::class)->events())->pluck('command')->filter();
        $backupScheduled = $scheduledCommands->contains(fn (string $command) => str_contains($command, 'backup:database') && str_contains($command, '--verify'));
        $cleanupScheduled = $scheduledCommands->contains(fn (string $command) => str_contains($command, 'backup:cleanup'));
        $restoreScheduled = $scheduledCommands->contains(fn (string $command) => str_contains($command, 'backup:restore'));
        $check('Verified backup schedule', $backupScheduled, 'backup:database --verify must be registered');
        $check('Backup cleanup schedule', $cleanupScheduled, 'backup:cleanup must be registered');
        $check('Automatic restore schedule', ! $restoreScheduled, 'restore and restore-test must never be scheduled');
        $this->line('[INFO] Queue: current critical financial and database notification flows are synchronous.');
        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }

    private function binaryAvailable(string $binary): bool
    {
        if ($binary === '' || str_contains($binary, "\0")) return false;
        if (str_contains($binary, DIRECTORY_SEPARATOR)) return is_file($binary) && is_executable($binary);
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            $path = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$binary;
            if (is_file($path) && is_executable($path)) return true;
        }
        return false;
    }
}
