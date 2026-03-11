<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortfolioAssetSnapshot extends Model
{
    use HasFactory;

    protected $table = 'portfolio_asset_snapshots';

    public $timestamps = false; // Only has created_at

    protected $fillable = [
        'user_id',
        'land_id',
        'snapshot_date',
        'units',
        'value_kobo',
        'created_at',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'units' => 'decimal:8',
        'value_kobo' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * Get the user that owns this asset snapshot
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the land associated with this snapshot
     */
    public function land(): BelongsTo
    {
        return $this->belongsTo(Land::class);
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
     * Get value in Naira (converted from kobo)
     */
    public function getValueNairaAttribute(): float
    {
        return $this->value_kobo / 100;
    }

    /**
     * Get formatted value in Naira
     */
    public function getFormattedValueAttribute(): string
    {
        return '₦' . number_format($this->value_naira, 2);
    }
}