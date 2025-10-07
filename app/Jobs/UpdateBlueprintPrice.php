<?php

namespace App\Jobs;

use AllowDynamicProperties;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

#[AllowDynamicProperties]
class UpdateBlueprintPrice implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct($trackedBlueprint, $registerAllPrices = false)
    {
        $this->trackedBlueprint = $trackedBlueprint;
        $this->registerAllPrices = $registerAllPrices;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        dd("stop");
        foreach($this->trackedBlueprint->languages as $language) {
            $response = Http::withToken(config('app.ct_api_token'))->get('https://api.cardtrader.com/api/v2/marketplace/products?blueprint_id=' . $this->trackedBlueprint->blueprint_id . '&language=' . $language);
            if($response->successful()) {
                $responseData = $response->json();
                dd($responseData);
            }
        }
    }
}
