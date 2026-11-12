<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('jogger:prune')->dailyAt('03:15')->onOneServer();
Schedule::command('jogger:prune-hosts')->dailyAt('03:45')->onOneServer();
Schedule::command('jogger:mark-offline-hosts')->everyTenSeconds()->withoutOverlapping();
Schedule::command('jogger:redeliver-commands')->everyFifteenSeconds()->withoutOverlapping();
Schedule::command('jogger:flush-logs')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('jogger:reap-runners')->everyThirtySeconds()->withoutOverlapping();
Schedule::command('jogger:reconcile-pools')->everyFifteenSeconds()->withoutOverlapping();
Schedule::command('jogger:reconcile')->everyMinute()->withoutOverlapping();
Schedule::command('jogger:scale-cloud-hosts')->everyMinute()->withoutOverlapping();
Schedule::command('jogger:reconcile-cloud-hosts')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('jogger:rollup-metrics')->everyMinute()->withoutOverlapping();
Schedule::command('jogger:evaluate-alerts')->everyMinute()->withoutOverlapping();
