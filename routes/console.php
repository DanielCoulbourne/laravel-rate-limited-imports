<?php

use App\Actions\StartScheduledImport;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Run every minute to check for overdue scheduled imports
Schedule::call(function () {
    StartScheduledImport::dispatch();
})->everyMinute()->name('start-scheduled-imports');
