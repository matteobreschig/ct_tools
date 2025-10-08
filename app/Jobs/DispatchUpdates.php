<?php

namespace App\Jobs;

use App\Models\TrackedBlueprint;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchUpdates implements ShouldQueue
{
    use Queueable;

    public bool $registerAllPrices;
    /**
     * Create a new job instance.
     */
    public function __construct($registerAllPrices = false)
    {
        $this->registerAllPrices = $registerAllPrices;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $trackedBlueprints = TrackedBlueprint::where('active', 1)->get();
        foreach ($trackedBlueprints as $trackedBlueprint) {
            UpdateBlueprintPrice::dispatch($trackedBlueprint, $this->registerAllPrices);
        }
    }
}
