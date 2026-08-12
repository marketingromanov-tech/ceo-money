<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Services\AdminNotificationService;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('backup:database --verify')
    ->dailyAt('03:00')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(120)
    ->onOneServer()
    ->onFailure(fn () => app(AdminNotificationService::class)->backupFailed('backup:database --verify'));

Schedule::command('backup:cleanup')
    ->dailyAt('03:30')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(120)
    ->onOneServer()
    ->onFailure(fn () => app(AdminNotificationService::class)->backupFailed('backup:cleanup'));
