<?php

namespace Tests\Feature;

use App\Models\BackupRecord;
use App\Models\User;
use App\Services\DatabaseBackupService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DatabaseBackupServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_verifies_a_private_gzip_backup_by_size_checksum_and_sql_structure(): void
    {
        Storage::fake('backup');
        $sql = "-- MySQL dump\nCREATE TABLE `users` (`id` bigint);\n".str_repeat('-- payload'."\n", 20);
        $content = gzencode($sql, 9);
        Storage::disk('backup')->put('database/valid.sql.gz', $content);
        $record = $this->record('database/valid.sql.gz', $content);

        $verified = app(DatabaseBackupService::class)->verify($record);

        $this->assertSame(BackupRecord::VERIFIED, $verified->status);
        $this->assertNotNull($verified->verified_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'backup.database_verified']);
    }

    public function test_checksum_mismatch_is_rejected(): void
    {
        Storage::fake('backup');
        $content = gzencode("-- MySQL dump\nCREATE TABLE `users` (`id` bigint);\n".str_repeat('x', 100));
        Storage::disk('backup')->put('database/invalid.sql.gz', $content);
        $record = $this->record('database/invalid.sql.gz', $content);
        $record->update(['sha256' => str_repeat('0', 64)]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('checksum mismatch');
        app(DatabaseBackupService::class)->verify($record->fresh());
    }

    public function test_backup_path_cannot_escape_the_private_backup_root(): void
    {
        Storage::fake('backup');
        $record = BackupRecord::create([
            'filename' => 'escape.sql.gz', 'storage_disk' => 'backup', 'storage_path' => '../escape.sql.gz',
            'status' => BackupRecord::COMPLETED, 'started_at' => now(),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('outside configured root');
        app(DatabaseBackupService::class)->verify($record);
    }

    public function test_admin_settings_lists_backup_metadata_but_has_no_restore_action(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        BackupRecord::create([
            'filename' => 'ceo-money-db-demo.sql.gz', 'storage_disk' => 'backup',
            'storage_path' => 'database/ceo-money-db-demo.sql.gz', 'size_bytes' => 1024,
            'sha256' => str_repeat('a', 64), 'status' => BackupRecord::VERIFIED, 'started_at' => now(),
        ]);

        $this->actingAs($admin)->get(route('admin.settings.index'))
            ->assertOk()->assertSee('ceo-money-db-demo.sql.gz')->assertDontSee('Восстановить');
    }

    private function record(string $path, string $content): BackupRecord
    {
        return BackupRecord::create([
            'filename' => basename($path), 'storage_disk' => 'backup', 'storage_path' => $path,
            'size_bytes' => strlen($content), 'sha256' => hash('sha256', $content),
            'status' => BackupRecord::COMPLETED, 'started_at' => now(), 'completed_at' => now(),
        ]);
    }
}
