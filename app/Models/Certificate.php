<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Certificate extends Model
{
    protected $fillable = [
        'user_id', 'land_id', 'purchase_id',
        'cert_number', 'digital_signature',
        'owner_name', 'units', 'total_invested', 'purchase_reference',
        'property_title', 'property_location',
        'plot_identifier', 'tenure', 'lga', 'state',
        'pdf_path', 'status', 'issued_at',
    ];

    protected $casts = [
        'units'           => 'integer',
        'total_invested'  => 'float',
        'issued_at'       => 'datetime',
        'revoked_at'      => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function land()
    {
        return $this->belongsTo(Land::class);
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}