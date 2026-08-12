<?php

namespace Tests\Feature;

use App\Models\BackupRecord;
use App\Services\DatabaseRestoreTestService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DatabaseRestoreTestSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_restore_test_refuses_the_current_application_database(): void
    {
        Storage::fake('backup');
        $content = gzencode("-- MySQL dump\nCREATE TABLE `users` (`id` bigint);\n".str_repeat('x', 100));
        Storage::disk('backup')->put('database/test.sql.gz', $content);
        $record = BackupRecord::create([
            'filename' => 'test.sql.gz', 'storage_disk' => 'backup', 'storage_path' => 'database/test.sql.gz',
            'size_bytes' => strlen($content), 'sha256' => hash('sha256', $content),
            'status' => BackupRecord::COMPLETED, 'started_at' => now(),
        ]);
        $this->configureOperationalCredentials();
        config(['backup.restore_test_database' => ' `'.strtoupper((string) config('database.connections.mysql.database')).'` ']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('must not be the current application database');
        app(DatabaseRestoreTestService::class)->run($record);
    }

    public function test_restore_test_fails_closed_without_operational_credentials_and_never_falls_back(): void
    {
        $record = $this->backupRecord();
        config([
            'database.connections.mysql.username' => 'application-user-sentinel',
            'database.connections.mysql.password' => 'application-password-sentinel',
            'backup.database' => ['host' => null, 'port' => 3306, 'username' => null, 'password' => null],
            'backup.restore_test_database' => 'isolated_restore_test',
        ]);

        try {
            app(DatabaseRestoreTestService::class)->run($record);
            $this->fail('Expected fail-closed operational credentials error.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Operational restore-test database credentials are not configured', $exception->getMessage());
            $this->assertStringNotContainsString('application-user-sentinel', $exception->getMessage());
            $this->assertStringNotContainsString('application-password-sentinel', $exception->getMessage());
        }
    }

    public function test_operational_credentials_are_never_exposed_by_restore_command_output(): void
    {
        $record = $this->backupRecord();
        config([
            'backup.database' => ['host' => '*', 'port' => 3306, 'username' => 'backup-secret-user', 'password' => 'backup-secret-password'],
            'backup.restore_test_database' => 'isolated_restore_test',
        ]);

        $this->artisan('backup:restore-test', ['backup' => $record->id])
            ->assertFailed()
            ->doesntExpectOutputToContain('backup-secret-user')
            ->doesntExpectOutputToContain('backup-secret-password');
    }

    private function configureOperationalCredentials(): void
    {
        config(['backup.database' => ['host' => 'mysql', 'port' => 3306, 'username' => 'restore-operator', 'password' => 'test-secret']]);
    }

    private function backupRecord(): BackupRecord
    {
        Storage::fake('backup');
        $content = gzencode("-- MySQL dump\nCREATE TABLE `users` (`id` bigint);\n".str_repeat('x', 100));
        Storage::disk('backup')->put('database/safety.sql.gz', $content);
        return BackupRecord::create([
            'filename' => 'safety.sql.gz', 'storage_disk' => 'backup', 'storage_path' => 'database/safety.sql.gz',
            'size_bytes' => strlen($content), 'sha256' => hash('sha256', $content),
            'status' => BackupRecord::COMPLETED, 'started_at' => now(),
        ]);
    }
}
