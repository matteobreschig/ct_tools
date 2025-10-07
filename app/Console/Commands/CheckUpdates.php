<?php

namespace App\Console\Commands;

use App\Jobs\DispatchUpdates;
use Illuminate\Console\Command;

class CheckUpdates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:check-updates';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        DispatchUpdates::dispatch();
    }
}
