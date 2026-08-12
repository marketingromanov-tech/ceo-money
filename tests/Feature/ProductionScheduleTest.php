<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AdminNotificationService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_backup_and_cleanup_are_scheduled_with_cluster_safe_overlap_protection(): void
    {
        $events = collect(app(Schedule::class)->events());
        $backup = $this->eventFor($events, 'backup:database');
        $cleanup = $this->eventFor($events, 'backup:cleanup');

        $this->assertStringContainsString('--verify', $backup->command);
        $this->assertSame('0 3 * * *', $backup->expression);
        $this->assertSame('30 3 * * *', $cleanup->expression);
        $this->assertSame(config('app.timezone'), $backup->timezone);
        $this->assertSame(config('app.timezone'), $cleanup->timezone);

        foreach ([$backup, $cleanup] as $event) {
            $this->assertTrue($event->withoutOverlapping);
            $this->assertTrue($event->onOneServer);
            $this->assertSame(120, $event->expiresAt);
        }

        $commands = $events->pluck('command')->filter()->implode("\n");
        $this->assertStringNotContainsString('backup:restore', $commands);
        $this->assertStringNotContainsString('migrate', $commands);
    }

    public function test_backup_failure_reuses_admin_database_notifications(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        app(AdminNotificationService::class)->backupFailed('backup:database --verify');

        $notification = $admin->notifications()->sole();
        $this->assertSame('backup.failed', $notification->data['event']);
        $this->assertSame('backup:database --verify', $notification->data['command']);
        $this->assertSame(route('admin.settings.index'), $notification->data['target']);
    }

    private function eventFor($events, string $command): Event
    {
        $event = $events->first(fn (Event $event) => str_contains((string) $event->command, $command));
        $this->assertInstanceOf(Event::class, $event);

        return $event;
    }
}
