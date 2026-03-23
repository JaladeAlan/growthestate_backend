<?php

namespace App\Listeners;

use App\Events\LandUnitsPurchased;
use App\Models\LandPriceHistory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class UpdatePortfolioOnPurchase implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(LandUnitsPurchased $event): void
    {
        $key = 'event:portfolio:' . $event->reference;

        if (! Cache::add($key, true, 300)) {
            Log::info('Duplicate portfolio update skipped', [
                'reference' => $event->reference,
            ]);
            return;
        }

        try {
            $date = now()->toDateString();

            $price = LandPriceHistory::currentPrice($event->landId);

            if (! $price) {
                throw new \Exception('Price not found yet');
            }

            $landValue = $event->units * $price->price_per_unit_kobo;

            DB::transaction(function () use ($event, $date, $landValue) {

                DB::statement("
                    INSERT INTO portfolio_land_snapshots
                    (user_id, land_id, snapshot_date, units_owned, invested_kobo,
                     land_value_kobo, profit_loss_kobo, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, now())
                    ON CONFLICT (user_id, land_id, snapshot_date)
                    DO UPDATE SET
                        units_owned      = portfolio_land_snapshots.units_owned + EXCLUDED.units_owned,
                        invested_kobo    = portfolio_land_snapshots.invested_kobo + EXCLUDED.invested_kobo,
                        land_value_kobo  = portfolio_land_snapshots.land_value_kobo + EXCLUDED.land_value_kobo,
                        profit_loss_kobo = (portfolio_land_snapshots.land_value_kobo + EXCLUDED.land_value_kobo)
                                         - (portfolio_land_snapshots.invested_kobo + EXCLUDED.invested_kobo)
                ", [
                    $event->userId,
                    $event->landId,
                    $date,
                    $event->units,
                    $event->totalCost,
                    $landValue,
                    $landValue - $event->totalCost,
                ]);

                DB::statement("
                    INSERT INTO portfolio_daily_snapshots
                    (user_id, snapshot_date, total_units, total_invested_kobo,
                     total_portfolio_value_kobo, profit_loss_kobo, profit_loss_percent, created_at)
                    SELECT
                        user_id,
                        snapshot_date,
                        SUM(units_owned),
                        SUM(invested_kobo),
                        SUM(land_value_kobo),
                        SUM(land_value_kobo) - SUM(invested_kobo),
                        CASE WHEN SUM(invested_kobo) > 0
                            THEN ROUND(
                                ((SUM(land_value_kobo) - SUM(invested_kobo))::numeric
                                 / SUM(invested_kobo)) * 100, 2)
                            ELSE 0
                        END,
                        now()
                    FROM portfolio_land_snapshots
                    WHERE user_id = ?
                      AND snapshot_date = ?
                    GROUP BY user_id, snapshot_date
                    ON CONFLICT (user_id, snapshot_date)
                    DO UPDATE SET
                        total_units                = EXCLUDED.total_units,
                        total_invested_kobo        = EXCLUDED.total_invested_kobo,
                        total_portfolio_value_kobo = EXCLUDED.total_portfolio_value_kobo,
                        profit_loss_kobo           = EXCLUDED.profit_loss_kobo,
                        profit_loss_percent        = EXCLUDED.profit_loss_percent
                ", [
                    $event->userId,
                    $date,
                ]);
            });

            Log::info('Portfolio updated on purchase', [
                'user_id' => $event->userId,
                'land_id' => $event->landId,
            ]);

        } catch (\Throwable $e) {
            Cache::forget($key);

            Log::error('UpdatePortfolioOnPurchase failed', [
                'error'   => $e->getMessage(),
                'user_id' => $event->userId ?? null,
                'land_id' => $event->landId ?? null,
            ]);

            throw $e; // allow retry
        }
    }
}