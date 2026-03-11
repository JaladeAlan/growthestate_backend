<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LandUnitsPurchased
{
    use Dispatchable, SerializesModels;

    public bool $dispatchAfterCommit = true;

    public function __construct(
        public int $userId,
        public int $landId,
        public int $units,
        public int $pricePerUnitKobo,
        public int $totalCost
    ) {}
}
