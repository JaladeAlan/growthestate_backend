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

    public function listing()
    {
        return $this->belongsTo(MarketplaceListing::class, 'listing_id');
    }

    public function buyer()
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function escrow()
    {
        return $this->hasOne(MarketplaceEscrow::class, 'offer_id');
    }

    public function getOfferPriceNairaAttribute(): float
    {
        return $this->offer_price_kobo / 100;
    }

    public function getTotalNairaAttribute(): float
    {
        return ($this->offer_price_kobo * $this->units) / 100;
    }
}


class MarketplaceEscrow extends Model
{
    protected $fillable = [
        'listing_id', 'offer_id', 'buyer_id', 'seller_id', 'land_id',
        'units', 'price_per_unit_kobo', 'total_kobo',
        'platform_fee_kobo', 'seller_receives_kobo',
        'status', 'payment_reference',
        'paid_at', 'completed_at', 'expires_at',
    ];

    protected $casts = [
        'units'                 => 'integer',
        'price_per_unit_kobo'   => 'integer',
        'total_kobo'            => 'integer',
        'platform_fee_kobo'     => 'integer',
        'seller_receives_kobo'  => 'integer',
        'paid_at'               => 'datetime',
        'completed_at'          => 'datetime',
        'expires_at'            => 'datetime',
    ];

    protected $appends = ['total_naira', 'seller_receives_naira'];

    public function listing()  { return $this->belongsTo(MarketplaceListing::class, 'listing_id'); }
    public function offer()    { return $this->belongsTo(MarketplaceOffer::class, 'offer_id'); }
    public function buyer()    { return $this->belongsTo(User::class, 'buyer_id'); }
    public function seller()   { return $this->belongsTo(User::class, 'seller_id'); }
    public function land()     { return $this->belongsTo(Land::class); }

    public function getTotalNairaAttribute(): float
    {
        return $this->total_kobo / 100;
    }

    public function getSellerReceivesNairaAttribute(): float
    {
        return $this->seller_receives_kobo / 100;
    }
}


class MarketplaceMessage extends Model
{
    protected $fillable = [
        'listing_id', 'sender_id', 'receiver_id', 'body', 'is_read',
    ];

    protected $casts = ['is_read' => 'boolean'];

    public function sender()   { return $this->belongsTo(User::class, 'sender_id'); }
    public function receiver() { return $this->belongsTo(User::class, 'receiver_id'); }
    public function listing()  { return $this->belongsTo(MarketplaceListing::class, 'listing_id'); }
}