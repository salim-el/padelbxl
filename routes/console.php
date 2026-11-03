<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('bookings:expire-holds')->everyMinute();
Schedule::command('accounts:anonymize')->dailyAt('03:00');
// Le journal des actions sensibles n'est gardé qu'un an (minimisation RGPD).
Schedule::command('activitylog:clean')->dailyAt('03:30');
