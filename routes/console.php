<?php

use App\Jobs\DispatchUpdates;
use App\Jobs\SyncCardtraderBlueprints;
use Illuminate\Console\Scheduling\Schedule;

app(Schedule::class)
    ->job(new DispatchUpdates)
    ->hourly()
    ->withoutOverlapping();

app(Schedule::class)
    ->job(new SyncCardtraderBlueprints)
    ->daily()
    ->withoutOverlapping();
