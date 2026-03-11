<?php

namespace App\Http\Controllers;

use App\Models\Land;
use App\Models\Purchase;
use App\Services\PortfolioService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PortfolioController extends Controller
{
    public function __construct(private PortfolioService $portfolio) {}

    // GET /portfolio/summary
    public function summary(Request $request)
    {
        $summary = $this->portfolio->summary($request->user()->id);

        return response()->json(['success' => true, 'data' => $summary]);
    }

    // GET /portfolio/chart?days=30
    public function chart(Request $request)
    {
        $request->validate(['days' => 'sometimes|integer|in:7,14,30,90,180,365']);
        $days = (int) $request->input('days', 30);

        $snapshots = DB::table('portfolio_daily_snapshots')
            ->where('user_id', $request->user()->id)
            ->where('snapshot_date', '>=', now()->subDays($days)->toDateString())
            ->orderBy('snapshot_date')
            ->get(['snapshot_date', 'total_portfolio_value_kobo', 'profit_loss_kobo', 'profit_loss_percent']);

        return response()->json(['success' => true, 'data' => $snapshots]);
    }

    // GET /portfolio/performance
    public function performance(Request $request)
    {
        $userId = $request->user()->id;

        $snapshots = DB::table('portfolio_daily_snapshots')
            ->where('user_id', $userId)
            ->orderBy('snapshot_date')
            ->get(['snapshot_date', 'total_invested_kobo', 'total_portfolio_value_kobo', 'profit_loss_kobo', 'profit_loss_percent']);

        if ($snapshots->isEmpty()) {
            return response()->json(['success' => true, 'data' => null]);
        }

        $oldest = $snapshots->first();
        $latest = $snapshots->last();

        $daysSinceFirst = max(1, now()->diffInDays($oldest->snapshot_date));
        $invested       = $oldest->total_invested_kobo;
        $current        = $latest->total_portfolio_value_kobo;

        $annualizedReturn = ($invested > 0 && $current > 0)
            ? (pow($current / $invested, 365 / $daysSinceFirst) - 1) * 100
            : 0;

        return response()->json([
            'success' => true,
            'data'    => [
                'since'                   => $oldest->snapshot_date,
                'days_invested'           => $daysSinceFirst,
                'total_invested_kobo'     => $invested,
                'current_value_kobo'      => $current,
                'total_profit_loss_kobo'  => $latest->profit_loss_kobo,
                'total_roi_percent'       => $latest->profit_loss_percent,
                'annualized_return_pct'   => round($annualizedReturn, 2),
            ],
        ]);
    }

    // GET /portfolio/allocation
    public function allocation(Request $request)
    {
        $userId = $request->user()->id;

        $latest = DB::table('portfolio_daily_snapshots')
            ->where('user_id', $userId)
            ->orderByDesc('snapshot_date')
            ->value('snapshot_date');

        if (! $latest) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $landSnapshots = DB::table('portfolio_land_snapshots as pls')
            ->join('lands as l', 'l.id', '=', 'pls.land_id')
            ->where('pls.user_id', $userId)
            ->where('pls.snapshot_date', $latest)
            ->select(
                'l.id',
                'l.title',
                'pls.units_owned',
                'pls.land_value_kobo',
                'pls.invested_kobo',
                'pls.profit_loss_kobo'
            )
            ->get();

        $totalValue = $landSnapshots->sum('land_value_kobo');

        $allocation = $landSnapshots->map(fn ($row) => [
            'land_id'            => $row->id,
            'land_title'         => $row->title,
            'units_owned'        => $row->units_owned,
            'land_value_kobo'    => $row->land_value_kobo,
            'invested_kobo'      => $row->invested_kobo,
            'profit_loss_kobo'   => $row->profit_loss_kobo,
            'allocation_percent' => $totalValue > 0
                ? round(($row->land_value_kobo / $totalValue) * 100, 2)
                : 0,
        ]);

        return response()->json(['success' => true, 'data' => $allocation]);
    }

    // GET /portfolio/asset/{land}
    public function asset(Request $request, Land $land)
    {
        $userId = $request->user()->id;

        $purchase = Purchase::where('user_id', $userId)
            ->where('land_id', $land->id)
            ->first();

        if (! $purchase) {
            return response()->json(['success' => false, 'message' => 'You do not own units in this land.'], 404);
        }

        $snapshots = DB::table('portfolio_land_snapshots')
            ->where('user_id', $userId)
            ->where('land_id', $land->id)
            ->orderBy('snapshot_date')
            ->get(['snapshot_date', 'units_owned', 'land_value_kobo', 'invested_kobo', 'profit_loss_kobo']);

        $currentPrice  = $land->current_price_per_unit_kobo;
        $currentValue  = $purchase->units * $currentPrice;
        $profitLoss    = $currentValue - $purchase->total_amount_paid_kobo;
        $profitLossPct = $purchase->total_amount_paid_kobo > 0
            ? round(($profitLoss / $purchase->total_amount_paid_kobo) * 100, 2)
            : 0;

        Log::info('Portfolio asset viewed', [
            'user_id' => $userId,
            'land_id' => $land->id,
        ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'land'                        => $land->only('id', 'title', 'location', 'size'),
                'units_owned'                 => $purchase->units,
                'units_sold'                  => $purchase->units_sold,
                'total_invested_kobo'         => $purchase->total_amount_paid_kobo,
                'current_price_per_unit_kobo' => $currentPrice,
                'current_value_kobo'          => $currentValue,
                'profit_loss_kobo'            => $profitLoss,
                'profit_loss_percent'         => $profitLossPct,
                'total_received_kobo'         => $purchase->total_amount_received_kobo,
                'first_purchased_at'          => $purchase->purchase_date,
                'historical_snapshots'        => $snapshots,
            ],
        ]);
    }
}