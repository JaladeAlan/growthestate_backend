<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Cache;
use App\Events\LandUnitsPurchased;
use App\Events\LandUnitsSold;
use App\Events\LandPriceChanged;

class ClearLandCache
{
    public function handle($event): void
    {
        if (! property_exists($event, 'landId')) {
            return;
        }

        Cache::tags(['lands:list', 'maps', 'admin:lands'])->flush();

        Cache::tags(['lands:item'])->forget("land:{$event->landId}:full");
        Cache::tags(['lands:item'])->forget("land:{$event->landId}:map");
    }
}
