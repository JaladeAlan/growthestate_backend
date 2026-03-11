<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReferralReward extends Model
{
    use HasFactory;

    protected $fillable = [
        'referral_id',
        'user_id',
        'reward_type',
        'amount_kobo',
        'units',
        'discount_percentage',
        'claimed',
        'claimed_at',
    ];

    protected $casts = [
        'claimed' => 'boolean',
        'claimed_at' => 'datetime',
    ];

    public function referral()
    {
        return $this->belongsTo(Referral::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function claim()
    {
        $this->update([
            'claimed' => true,
            'claimed_at' => now(),
        ]);
    }

    public function scopeUnclaimed($query)
    {
        return $query->where('claimed', false);
    }

    public function scopeClaimed($query)
    {
        return $query->where('claimed', true);
    }
}
