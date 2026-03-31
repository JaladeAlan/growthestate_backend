<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketplaceOffer extends Model
{
    protected $fillable = [
        'listing_id', 'buyer_id', 'units',
        'offer_price_kobo', 'status', 'message', 'expires_at',
    ];

    protected $casts = [
        'units'            => 'integer',
        'offer_price_kobo' => 'integer',
        'expires_at'       => 'datetime',
    ];

    protected $appends = ['offer_price_naira', 'total_naira'];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function listing()
    {
        return $this->belongsTo(MarketplaceListing::class, 'listing_id');
    }

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function transaction()
    {
        // Once accepted, the completed trade record
        return $this->hasOne(MarketplaceTransaction::class, 'offer_id');
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getOfferPriceNairaAttribute(): float
    {
        return $this->offer_price_kobo / 100;
    }

    public function getTotalNairaAttribute(): float
    {
        return ($this->offer_price_kobo * $this->units) / 100;
    }
}