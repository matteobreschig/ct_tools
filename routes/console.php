<?php

use Illuminate\Console\Scheduling\Schedule;

app(Schedule::class)
    ->job(new \App\Jobs\DispatchUpdates())
    ->hourly()
    ->withoutOverlapping();
