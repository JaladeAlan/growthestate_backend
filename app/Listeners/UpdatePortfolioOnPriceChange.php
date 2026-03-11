<?php

namespace App\Listeners;

use App\Events\LandPriceChanged;
use Illuminate\Support\Facades\DB;

class UpdatePortfolioOnPriceChange
{
    public function handle(LandPriceChanged $event): void
    {
        $date = $event->priceDate;

        DB::transaction(function () use ($event, $date) {

            // Update per-land values
            DB::statement("
                UPDATE portfolio_land_snapshots pls
                SET
                    land_value_kobo = pls.units_owned * ?,
                    profit_loss_kobo = (pls.units_owned * ?) - pls.invested_kobo
                WHERE pls.land_id = ?
                AND pls.snapshot_date = ?
            ", [
                $event->pricePerUnitKobo,
                $event->pricePerUnitKobo,
                $event->landId,
                $date
            ]);

            // Recalculate daily totals
            DB::statement("
                UPDATE portfolio_daily_snapshots pds
                SET
                    total_portfolio_value_kobo = x.total_value,
                    profit_loss_kobo = x.total_value - pds.total_invested_kobo,
                    profit_loss_percent = CASE
                        WHEN pds.total_invested_kobo > 0
                        THEN ROUND(((x.total_value - pds.total_invested_kobo)::numeric / pds.total_invested_kobo) * 100, 2)
                        ELSE 0
                    END
                FROM (
                    SELECT user_id, SUM(land_value_kobo) AS total_value
                    FROM portfolio_land_snapshots
                    WHERE land_id = ?
                    AND snapshot_date = ?
                    GROUP BY user_id
                ) x
                WHERE pds.user_id = x.user_id
                AND pds.snapshot_date = ?
            ", [
                $event->landId,
                $date,
                $date
            ]);
        });
    }
}
