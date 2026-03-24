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

        // Cost-basis from transactions
        $costBases = DB::table('transactions')
            ->select(
                'land_id',
                DB::raw('SUM(amount_kobo) as total_paid'),
                DB::raw('SUM(units)       as total_units_bought')
            )
            ->where('user_id', $userId)
            ->where('type', 'purchase')
            ->where('status', 'completed')
            ->whereIn('land_id', $landIds)
            ->groupBy('land_id')
            ->get()
            ->keyBy('land_id');

        $totalUnits    = 0;
        $totalInvested = 0;
        $totalValue    = 0;
        $landBreakdown = [];

        foreach ($userLands as $userLand) {
            $price        = $prices->get($userLand->land_id);
            $pricePerUnit = $price ? $price->price_per_unit_kobo : 0;
            $landValue    = $userLand->units * $pricePerUnit;

            // Weighted-average cost basis for units still held
            $costBasis        = $costBases->get($userLand->land_id);
            $totalUnitsBought = (int) ($costBasis->total_units_bought ?? 0);
            $totalPaid        = (int) ($costBasis->total_paid         ?? 0);
            $avgCostPerUnit   = $totalUnitsBought > 0 ? $totalPaid / $totalUnitsBought : 0;
            $landInvested     = (int) round($avgCostPerUnit * $userLand->units);

            $landProfitLoss        = $landValue - $landInvested;
            $landProfitLossPercent = $landInvested > 0
                ? round(($landProfitLoss / $landInvested) * 100, 2)
                : 0;

            $totalUnits    += $userLand->units;
            $totalValue    += $landValue;
            $totalInvested += $landInvested;

            $land = DB::table('lands')->find($userLand->land_id);

            $landBreakdown[] = [
                'land_id'                     => $userLand->land_id,
                'land_name'                   => $land->title ?? 'Unknown',
                'units'                       => $userLand->units,
                'avg_cost_per_unit_kobo'      => (int) round($avgCostPerUnit),
                'avg_cost_per_unit_naira'     => round($avgCostPerUnit / 100, 2),
                'price_per_unit_kobo'         => $pricePerUnit,
                'price_per_unit_naira'        => $pricePerUnit / 100,
                'cost_basis_kobo'             => $landInvested,
                'cost_basis_naira'            => $landInvested / 100,
                'total_portfolio_value_kobo'  => $landValue,
                'total_portfolio_value_naira' => $landValue / 100,
                'profit_loss_kobo'            => $landProfitLoss,
                'profit_loss_naira'           => $landProfitLoss / 100,
                'profit_loss_percent'         => $landProfitLossPercent,
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