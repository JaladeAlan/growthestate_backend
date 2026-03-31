<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
class MarketplaceTransaction extends Model
{
    protected $fillable = [
        'listing_id', 'offer_id', 'buyer_id', 'seller_id', 'land_id',
        'units', 'price_per_unit_kobo', 'total_kobo',
        'platform_fee_kobo', 'seller_receives_kobo',
        'reference', 'completed_at',
    ];

    protected $casts = [
        'units'                => 'integer',
        'price_per_unit_kobo'  => 'integer',
        'total_kobo'           => 'integer',
        'platform_fee_kobo'    => 'integer',
        'seller_receives_kobo' => 'integer',
        'completed_at'         => 'datetime',
    ];

    protected $appends = ['total_naira', 'seller_receives_naira'];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function buyer()   { return $this->belongsTo(User::class,               'buyer_id');  }
    public function seller()  { return $this->belongsTo(User::class,               'seller_id'); }
    public function land()    { return $this->belongsTo(Land::class);                            }
    public function listing() { return $this->belongsTo(MarketplaceListing::class, 'listing_id'); }
    public function offer()   { return $this->belongsTo(MarketplaceOffer::class,   'offer_id');  }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getTotalNairaAttribute(): float
    {
        return $this->total_kobo / 100;
    }

    public function getSellerReceivesNairaAttribute(): float
    {
        return $this->seller_receives_kobo / 100;
    }
}
