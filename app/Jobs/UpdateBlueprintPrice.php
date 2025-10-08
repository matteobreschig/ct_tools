<?php

namespace App\Jobs;

use App\Casts\MoneyCast;
use App\Models\TrackedBlueprint;
use App\Models\TrackedBlueprintNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

class UpdateBlueprintPrice implements ShouldQueue
{
    use Queueable;

    public bool $registerAllPrices;
    public TrackedBlueprint $trackedBlueprint;

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
        foreach($this->trackedBlueprint->languages as $language) {
            $response = Http::withToken(config('app.ct_api_token'))->get('https://api.cardtrader.com/api/v2/marketplace/products?blueprint_id=' . $this->trackedBlueprint->blueprint_id . '&language=' . $language->getLabel());
            if($response->successful()) {
                $responseData = $response->json();
                foreach($responseData[$this->trackedBlueprint->blueprint_id] as $item) {
                    //TODO: Salvarsi anche lo userid del venditore, se no viene continuamente salvato finchè non viene rimosso dal venditore
                    if(($item['price_cents'] / 100) < $this->trackedBlueprint->price && (int)$item['quantity'] >= $this->trackedBlueprint->quantity) {
                        $trackedBluePrintNotification = new TrackedBlueprintNotification();
                        $trackedBluePrintNotification->tracked_blueprint_id = $this->trackedBlueprint->id;
                        $trackedBluePrintNotification->price = $item['price_cents'];
                        $trackedBluePrintNotification->quantity = $item['quantity'];
                        $trackedBluePrintNotification->condition = 'nm';
                        $trackedBluePrintNotification->language = $language->getLabel();
                        $trackedBluePrintNotification->ct_zero = false;
                        $trackedBluePrintNotification->save();
                    }
                }
            }
        }
    }
}
