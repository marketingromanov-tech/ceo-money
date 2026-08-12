<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;

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

        $check('APP_ENV', app()->environment('production'), (string) app()->environment());
        $check('APP_DEBUG', ! config('app.debug'), config('app.debug') ? 'must be false' : 'false');
        $check('APP_URL', str_starts_with((string) config('app.url'), 'https://'), 'must use https://');
        $check('APP_KEY', filled(config('app.key')), filled(config('app.key')) ? 'configured' : 'missing');
        $check('Session driver', config('session.driver') === 'database', (string) config('session.driver'));
        $check('Session encryption', config('session.encrypt') === true, config('session.encrypt') ? 'enabled' : 'disabled');
        $check('Secure cookie', config('session.secure') === true, config('session.secure') ? 'enabled' : 'disabled');
        $check('Security headers', config('security.headers_enabled') === true, config('security.headers_enabled') ? 'enabled' : 'disabled');

        try { DB::connection()->getPdo(); $check('Database', true, 'connection successful'); }
        catch (\Throwable) { $check('Database', false, 'connection failed'); }

        try {
            $files = array_keys($migrator->getMigrationFiles(database_path('migrations')));
            $pending = array_diff($files, $migrator->getRepository()->getRan());
            $check('Migrations', $pending === [], $pending === [] ? 'up to date' : count($pending).' pending');
        } catch (\Throwable) { $check('Migrations', false, 'status unavailable'); }

        foreach ([storage_path('framework'), storage_path('logs'), base_path('bootstrap/cache')] as $path) {
            $check('Writable '.$path, is_dir($path) && is_writable($path), 'directory must be writable');
        }
        $check('Demo seeding protection', ! app()->environment(['local', 'testing']), 'DatabaseSeeder permits demo data only in local/testing');
        try {
            $withoutMfa = User::query()->where('role', 'admin')->where('is_active', true)->whereNull('mfa_enabled_at')->count();
            $check('Active admin MFA', $withoutMfa === 0, $withoutMfa.' active admins without MFA');
        } catch (\Throwable) { $check('Active admin MFA', false, 'unable to inspect'); }

        $this->line('[INFO] Scheduler: no scheduled application commands are currently registered.');
        $this->line('[INFO] Queue: current critical financial and database notification flows are synchronous.');
        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }
}
