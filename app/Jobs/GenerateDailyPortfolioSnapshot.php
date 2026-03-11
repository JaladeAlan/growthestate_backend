<?php

namespace App\Jobs;

use App\Models\LandPriceHistory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class GenerateDailyPortfolioSnapshot implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries   = 3;

    protected array $dates;

    // -------------------------------------------------------------------------

    /**
     * @param  string|array|null  $dates  ISO date string(s), or null for today.
     */
    public function __construct(string|array|null $dates = null)
    {
        if ($dates === null) {
            $this->dates = [now()->toDateString()];
        } elseif (is_string($dates)) {
            $this->dates = [$dates];
        } else {
            $this->dates = $dates;
        }

        // Validate all provided dates are parseable ISO dates.
        foreach ($this->dates as $date) {
            Carbon::parse($date); // throws ParseException on bad input
        }
    }

    // -------------------------------------------------------------------------

    public function handle(): void
    {
        foreach ($this->dates as $date) {
            $snapshotDate = Carbon::parse($date)->toDateString();
            Log::info('GenerateDailyPortfolioSnapshot: processing', ['date' => $snapshotDate]);

            if ($this->snapshotExists($snapshotDate)) {
                Log::info('GenerateDailyPortfolioSnapshot: snapshot already exists, skipping', [
                    'date' => $snapshotDate,
                ]);
                continue;
            }

            // Let exceptions bubble up so the queue marks the job as failed
            // and retries according to $tries / backoff settings.
            $this->generateSnapshot($snapshotDate);

            Log::info('GenerateDailyPortfolioSnapshot: completed', ['date' => $snapshotDate]);
        }
    }

    // -------------------------------------------------------------------------
    // Core logic
    // -------------------------------------------------------------------------

    protected function generateSnapshot(string $snapshotDate): void
    {
        DB::transaction(function () use ($snapshotDate) {

            // Re-check inside the transaction to guard against concurrent runs.
            if ($this->snapshotExists($snapshotDate)) {
                Log::warning('GenerateDailyPortfolioSnapshot: snapshot created by concurrent process', [
                    'date' => $snapshotDate,
                ]);
                return;
            }

            $prices   = $this->getPrices($snapshotDate);
            $holdings = $this->getHoldings();

            if ($prices->isEmpty() || $holdings->isEmpty()) {
                Log::warning('GenerateDailyPortfolioSnapshot: nothing to snapshot', [
                    'date'          => $snapshotDate,
                    'price_count'   => $prices->count(),
                    'holding_count' => $holdings->count(),
                ]);
                return;
            }

            [$assetRows, $userTotals, $userUnits] =
                $this->calculateAssetValues($holdings, $prices, $snapshotDate);

            // ── portfolio_asset_snapshots ──────────────────────────────────
            if (! empty($assetRows)) {
                foreach (array_chunk($assetRows, 500) as $chunk) {
                    DB::table('portfolio_asset_snapshots')->insert($chunk);
                }
                Log::info('GenerateDailyPortfolioSnapshot: asset rows inserted', [
                    'count' => count($assetRows),
                    'date'  => $snapshotDate,
                ]);
            }

            // ── portfolio_daily_snapshots ──────────────────────────────────
            $invested   = $this->getInvestedAmounts($snapshotDate);
            $dailyRows  = $this->calculateDailySnapshots(
                $userTotals,
                $userUnits,
                $invested,
                $snapshotDate
            );

            if (! empty($dailyRows)) {
                foreach (array_chunk($dailyRows, 500) as $chunk) {
                    DB::table('portfolio_daily_snapshots')->insert($chunk);
                }
                Log::info('GenerateDailyPortfolioSnapshot: daily rows inserted', [
                    'count' => count($dailyRows),
                    'date'  => $snapshotDate,
                ]);
            }
        });
    }

    // -------------------------------------------------------------------------
    // Data fetchers
    // -------------------------------------------------------------------------

    /**
     * Fetch the most-recent price per land as of $snapshotDate.
     *
     * Cache key includes the date so backfill runs for different dates
     * never share a stale result, and so a re-run on the same date still
     * uses a consistent price set for the whole transaction.
     */
    protected function getPrices(string $snapshotDate): \Illuminate\Support\Collection
    {
        $cacheKey = "snapshot_prices:{$snapshotDate}";

        return Cache::remember($cacheKey, now()->addMinutes(60), function () use ($snapshotDate) {
            $landIds = DB::table('user_land')
                ->where('units', '>', 0)
                ->distinct()
                ->pluck('land_id')
                ->toArray();

            if (empty($landIds)) {
                return collect();
            }

            // currentPricesForLands already does DISTINCT ON (land_id) ordered
            // by price_date DESC — i.e. the latest price on or before today.
            // For historical backfill we need prices as of $snapshotDate, so
            // we use the raw query path directly.
            $placeholders = implode(',', array_fill(0, count($landIds), '?'));
            $bindings     = array_merge($landIds, [$snapshotDate]);

            $rows = DB::select("
                SELECT DISTINCT ON (land_id)
                    land_id,
                    price_per_unit_kobo
                FROM land_price_history
                WHERE land_id IN ({$placeholders})
                  AND price_date <= ?
                ORDER BY land_id, price_date DESC, created_at DESC
            ", $bindings);

            return collect($rows)->keyBy('land_id');
        });
    }

    /**
     * Fetch current holdings (units > 0) for all users.
     *
     * Not cached: holdings reflect the state at job execution time, and
     * the job is idempotent (skips if snapshot already exists), so caching
     * could produce incorrect snapshots if the job retries after a buy/sell.
     */
    protected function getHoldings(): \Illuminate\Support\Collection
    {
        return DB::table('user_land')
            ->select('user_id', 'land_id', 'units')
            ->where('units', '>', 0)
            ->get();
    }

    /**
     * Net invested amount per user as of $snapshotDate.
     * = total paid − total received from sales up to that date.
     */
    protected function getInvestedAmounts(string $snapshotDate): \Illuminate\Support\Collection
    {
        return DB::table('purchases')
            ->select(
                'user_id',
                DB::raw('SUM(total_amount_paid_kobo)                                   AS total_paid'),
                DB::raw('SUM(total_amount_received_kobo)                               AS total_received'),
                DB::raw('SUM(total_amount_paid_kobo) - SUM(total_amount_received_kobo) AS invested')
            )
            ->whereDate('purchase_date', '<=', $snapshotDate)
            ->whereIn('status', ['active', 'completed', 'partially_sold'])
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');
    }

    // -------------------------------------------------------------------------
    // Calculators
    // -------------------------------------------------------------------------

    /**
     * @return array{0: array, 1: array<int,int>, 2: array<int,int>}
     *         [$assetRows, $userTotals (kobo), $userUnits]
     */
    protected function calculateAssetValues(
        \Illuminate\Support\Collection $holdings,
        \Illuminate\Support\Collection $prices,
        string $snapshotDate
    ): array {
        $assetRows  = [];
        $userTotals = [];
        $userUnits  = [];

        foreach ($holdings as $row) {
            $price = $prices->get($row->land_id);

            if (! $price) {
                Log::debug('GenerateDailyPortfolioSnapshot: no price for land', [
                    'land_id' => $row->land_id,
                    'date'    => $snapshotDate,
                ]);
                continue;
            }

            $valueKobo = (int) ($row->units * $price->price_per_unit_kobo);

            $assetRows[] = [
                'user_id'       => $row->user_id,
                'land_id'       => $row->land_id,
                'snapshot_date' => $snapshotDate,
                'units'         => $row->units,
                'value_kobo'    => $valueKobo,
                'created_at'    => now(),
            ];

            $userTotals[$row->user_id] = ($userTotals[$row->user_id] ?? 0) + $valueKobo;
            $userUnits[$row->user_id]  = ($userUnits[$row->user_id]  ?? 0) + $row->units;
        }

        return [$assetRows, $userTotals, $userUnits];
    }

    /**
     * @param  array<int,int>                    $userTotals  user_id → total value kobo
     * @param  array<int,int>                    $userUnits   user_id → total units
     * @param  \Illuminate\Support\Collection    $invested    keyed by user_id
     */
    protected function calculateDailySnapshots(
        array $userTotals,
        array $userUnits,
        \Illuminate\Support\Collection $invested,
        string $snapshotDate
    ): array {
        $rows = [];

        foreach ($userTotals as $userId => $totalValueKobo) {
            $investedKobo = (int) ($invested->get($userId)?->invested ?? 0);
            $profitLoss   = $totalValueKobo - $investedKobo;
            $plPercent    = $investedKobo > 0
                ? round(($profitLoss / $investedKobo) * 100, 2)
                : 0.0;

            $rows[] = [
                'user_id'                    => $userId,
                'snapshot_date'              => $snapshotDate,
                'total_units'                => $userUnits[$userId] ?? 0,
                'total_invested_kobo'        => $investedKobo,
                'total_portfolio_value_kobo' => $totalValueKobo, 
                'profit_loss_kobo'           => $profitLoss,
                'profit_loss_percent'        => $plPercent,
                'created_at'                 => now(),
            ];
        }

        return $rows;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function snapshotExists(string $date): bool
    {
        return DB::table('portfolio_daily_snapshots')
            ->where('snapshot_date', $date)
            ->exists();
    }
}