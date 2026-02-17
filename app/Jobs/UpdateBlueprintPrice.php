<?php

namespace App\Jobs;

use App\Enums\ConditionType;
use App\Models\TrackedBlueprint;
use App\Models\TrackedBlueprintNotification;
use App\Services\CTApiClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Str;

class UpdateBlueprintPrice implements ShouldQueue
{
    use Queueable;

    public TrackedBlueprint $trackedBlueprint;
    public bool $registerAllPrices;

    /**
     * Create a new job instance.
     */
    public function __construct(TrackedBlueprint $trackedBlueprint, $registerAllPrices = false)
    {
        $this->trackedBlueprint = $trackedBlueprint;
        $this->registerAllPrices = $registerAllPrices;
    }

    public function middleware(): array
    {
        return [new RateLimited('update-blueprint-price')];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $client = new CTApiClient($this->trackedBlueprint->user);
        foreach($this->trackedBlueprint->languages as $language) {
            $response = $client->get('/marketplace/products', ['blueprint_id' => $this->trackedBlueprint->blueprint_id, 'language' => Str::lower($language->getLabel())]);
            if($response->successful()) {
                $responseData = $response->json();
                foreach($responseData[$this->trackedBlueprint->blueprint_id] as $item) {
                    if(($item['price_cents'] / 100) < $this->trackedBlueprint->price) {
                        if((int)$item['quantity'] >= $this->trackedBlueprint->quantity) {
                            if($this->trackedBlueprint->conditions->contains(ConditionType::from(ConditionType::fromApiLabel($item['properties_hash']['condition'])))) {
                                if($this->trackedBlueprint->ct_zero_only && !$item['user']['can_sell_sealed_with_ct_zero']) {
                                    continue;
                                }
                                else {
                                    TrackedBlueprintNotification::updateOrCreate(
                                        [
                                            'product_id' => $item['id']
                                        ],
                                        [
                                            'tracked_blueprint_id' => $this->trackedBlueprint->id,
                                            'price' => $item['price_cents'] / 100,
                                            'quantity' => $item['quantity'],
                                            'condition' => ConditionType::fromApiLabel($item['properties_hash']['condition']),
                                            'language' => $language->getLabel(),
                                            'ct_zero' => $item['user']['can_sell_sealed_with_ct_zero'],
                                            'ct_user_name' => $item['user']['username'],
                                            'ct_user_country_code' => $item['user']['country_code'],
                                        ]
                                    );
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}
