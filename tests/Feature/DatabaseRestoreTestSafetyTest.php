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
        config(['backup.restore_test_database' => config('database.connections.mysql.database')]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('must not be the current application database');
        app(DatabaseRestoreTestService::class)->run($record);
    }
}
