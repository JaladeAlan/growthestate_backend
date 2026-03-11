<?php

namespace App\Services;

use App\Models\LandPriceHistory;
use Illuminate\Support\Facades\DB;

class PortfolioService
{
    public static function summary(int $userId): array
    {
        $userLands = DB::table('user_land')
            ->where('user_id', $userId)
            ->where('units', '>', 0)
            ->get();

        if ($userLands->isEmpty()) {
            return [
                'total_units'                   => 0,
                'total_invested_kobo'           => 0,
                'total_invested_naira'          => 0,
                'current_portfolio_value_kobo'  => 0,
                'current_portfolio_value_naira' => 0,
                'total_profit_loss_kobo'        => 0,
                'total_profit_loss_naira'       => 0,
                'profit_loss_percent'           => 0,
                'lands'                         => [],
            ];
        }

        $landIds = $userLands->pluck('land_id')->toArray();
        $prices  = LandPriceHistory::currentPricesForLands($landIds);

        $investedData = DB::table('purchases')
            ->select(
                DB::raw('SUM(total_amount_paid_kobo) as total_paid'),
                DB::raw('SUM(total_amount_received_kobo) as total_received')
            )
            ->where('user_id', $userId)
            ->whereIn('status', ['completed', 'partially_sold'])
            ->first();

        $totalInvested = ($investedData->total_paid ?? 0) - ($investedData->total_received ?? 0);

        $totalUnits    = 0;
        $totalValue    = 0;
        $landBreakdown = [];

        foreach ($userLands as $userLand) {
            $price        = $prices->get($userLand->land_id);
            $pricePerUnit = $price ? $price->price_per_unit_kobo : 0;
            $landValue    = $userLand->units * $pricePerUnit;

            $totalUnits += $userLand->units;
            $totalValue += $landValue;

            $land = DB::table('lands')->find($userLand->land_id);

            $landBreakdown[] = [
                'land_id'                    => $userLand->land_id,
                'land_name'                  => $land->title ?? 'Unknown',
                'units'                      => $userLand->units,
                'price_per_unit_kobo'        => $pricePerUnit,
                'price_per_unit_naira'       => $pricePerUnit / 100,
                'total_portfolio_value_kobo' => $landValue,
                'total_portfolio_value_naira'=> $landValue / 100,
            ];
        }

        $profitLoss        = $totalValue - $totalInvested;
        $profitLossPercent = $totalInvested > 0
            ? round(($profitLoss / $totalInvested) * 100, 2)
            : 0;

        return [
            'total_units'                   => $totalUnits,
            'total_invested_kobo'           => $totalInvested,
            'total_invested_naira'          => $totalInvested / 100,
            'current_portfolio_value_kobo'  => $totalValue,
            'current_portfolio_value_naira' => $totalValue / 100,
            'total_profit_loss_kobo'        => $profitLoss,
            'total_profit_loss_naira'       => $profitLoss / 100,
            'profit_loss_percent'           => $profitLossPercent,
            'lands'                         => $landBreakdown,
        ];
    }
}