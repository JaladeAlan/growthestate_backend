<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PortfolioDailySnapshot extends Model
{
    use HasFactory;

    protected $table = 'portfolio_daily_snapshots';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'snapshot_date',
        'total_units',
        'total_invested_kobo',
        'total_portfolio_value_kobo',
        'profit_loss_kobo',
        'profit_loss_percent',
        'created_at',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'total_units' => 'decimal:8',
        'total_invested_kobo' => 'integer',
        'total_portfolio_value_kobo' => 'integer',
        'profit_loss_kobo' => 'integer',
        'profit_loss_percent' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    /**
     * Get the user that owns this snapshot
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the asset snapshots for this daily snapshot
     */
    public function assetSnapshots(): HasMany
    {
        return $this->hasMany(AssetSnapshot::class, 'user_id', 'user_id')
            ->where('snapshot_date', $this->snapshot_date);
    }

    /**
     * Scope: Get snapshots for a specific date
     */
    public function scopeForDate($query, $date)
    {
        return $query->where('snapshot_date', $date);
    }

    /**
     * Scope: Get snapshots for a specific user
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: Get snapshots for a date range
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('snapshot_date', [$startDate, $endDate]);
    }

    /**
     * Scope: Get snapshots with profit
     */
    public function scopeWithProfit($query)
    {
        return $query->where('profit_loss_kobo', '>', 0);
    }

    /**
     * Scope: Get snapshots with loss
     */
    public function scopeWithLoss($query)
    {
        return $query->where('profit_loss_kobo', '<', 0);
    }

    /**
     * Get total invested in Naira
     */
    public function getTotalInvestedNairaAttribute(): float
    {
        return $this->total_invested_kobo / 100;
    }

    /**
     * Get portfolio value in Naira
     */
    public function getPortfolioValueNairaAttribute(): float
    {
        return $this->total_portfolio_value_kobo / 100;
    }

    /**
     * Get profit/loss in Naira
     */
    public function getProfitLossNairaAttribute(): float
    {
        return $this->profit_loss_kobo / 100;
    }

    /**
     * Get formatted total invested
     */
    public function getFormattedInvestedAttribute(): string
    {
        return '₦' . number_format($this->total_invested_naira, 2);
    }

    /**
     * Get formatted portfolio value
     */
    public function getFormattedPortfolioValueAttribute(): string
    {
        return '₦' . number_format($this->portfolio_value_naira, 2);
    }

    /**
     * Get formatted profit/loss
     */
    public function getFormattedProfitLossAttribute(): string
    {
        $value = $this->profit_loss_naira;
        $sign = $value >= 0 ? '+' : '';
        return $sign . '₦' . number_format($value, 2);
    }

    /**
     * Get formatted profit/loss percentage
     */
    public function getFormattedProfitLossPercentAttribute(): string
    {
        $sign = $this->profit_loss_percent >= 0 ? '+' : '';
        return $sign . number_format($this->profit_loss_percent, 2) . '%';
    }

    /**
     * Check if portfolio is profitable
     */
    public function isProfitable(): bool
    {
        return $this->profit_loss_kobo > 0;
    }

    /**
     * Check if portfolio has loss
     */
    public function hasLoss(): bool
    {
        return $this->profit_loss_kobo < 0;
    }

    /**
     * Check if portfolio is break even
     */
    public function isBreakEven(): bool
    {
        return $this->profit_loss_kobo == 0;
    }
}