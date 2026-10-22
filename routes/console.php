<?php

use App\Models\PendingRegistration;
use App\Services\StationBackupRestorer;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Station sign-ups whose email was never confirmed (no station or database exists for them).
Schedule::command('model:prune', ['--model' => [PendingRegistration::class]])->hourly();

// Pre-restore snapshots: each station keeps its newest three (a restore also prunes right away).
Schedule::call(fn () => StationBackupRestorer::pruneSnapshots())->daily()->name('prune-restore-snapshots');
