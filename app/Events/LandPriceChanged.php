<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LandPriceChanged
{
    use Dispatchable, SerializesModels;

    public bool $dispatchAfterCommit = true;

    public function __construct(
        public int $landId,
        public int $pricePerUnitKobo,
        public string $priceDate
    ) {}
}
