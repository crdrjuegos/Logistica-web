<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// HU-023: control nocturno del ledger contra los saldos (hora de Lima).
Schedule::command('wms:reconcile')->dailyAt('02:00')->timezone('America/Lima')->withoutOverlapping();
