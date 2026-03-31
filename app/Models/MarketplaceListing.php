<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class MarketplaceListing extends Model
{
    protected $fillable = [
        'seller_id', 'land_id', 'units_for_sale',
        'asking_price_kobo', 'description', 'status', 'expires_at',
    ];

    protected $casts = [
        'units_for_sale'    => 'integer',
        'asking_price_kobo' => 'integer',
        'expires_at'        => 'datetime',
    ];

    protected $appends = ['asking_price_naira', 'is_expired'];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function land()
    {
        return $this->belongsTo(Land::class);
    }

    public function offers()
    {
        return $this->hasMany(MarketplaceOffer::class, 'listing_id');
    }

    public function pendingOffers()
    {
        return $this->hasMany(MarketplaceOffer::class, 'listing_id')
                    ->where('status', 'pending');
    }

    public function transactions()
    {
        return $this->hasMany(MarketplaceTransaction::class, 'listing_id');
    }

    public function messages()
    {
        return $this->hasMany(MarketplaceMessage::class, 'listing_id')
                    ->orderBy('created_at');
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getAskingPriceNairaAttribute(): float
    {
        return $this->asking_price_kobo / 100;
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'active')
                 ->where(fn ($q) =>
                     $q->whereNull('expires_at')
                       ->orWhere('expires_at', '>', now())
                 );
    }

    public function scopeForLand(Builder $q, int $landId): Builder
    {
        return $q->where('land_id', $landId);
    }
}
