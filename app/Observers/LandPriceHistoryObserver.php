<?php

namespace App\Observers;

use App\Models\LandPriceHistory;
use Illuminate\Support\Facades\Cache;

class LandPriceHistoryObserver
{
    public function created(LandPriceHistory $landPriceHistory)
    {
        $this->clearLandCache($landPriceHistory->land_id);
    }

    public function updated(LandPriceHistory $landPriceHistory)
    {
        $this->clearLandCache($landPriceHistory->land_id);
    }

    public function deleted(LandPriceHistory $landPriceHistory)
    {
        $this->clearLandCache($landPriceHistory->land_id);
    }

    protected function clearLandCache($landId)
    {
        // Clear specific land cache
        Cache::tags(['lands:item'])->forget("land:{$landId}:map");
        Cache::tags(['lands:item'])->forget("land:{$landId}:full");
        
        // Clear list cache (if you have one)
        Cache::tags(['lands:list'])->flush();

        Cache::forget("land_prices_latest");
        Cache::forget("user_holdings_current");
        
        // Clear date-specific caches (if applicable)
        $today = now()->toDateString();
        Cache::forget("land_prices_as_of_{$today}");
    }
}