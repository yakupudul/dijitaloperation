<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('async:mark-stale-runs')->everyFiveMinutes();
Schedule::command('reports:dispatch-due-deliveries')->everyFiveMinutes();
Schedule::command('moxdop:dispatch-due-automations')->everyFiveMinutes();
