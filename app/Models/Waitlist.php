<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Waitlist extends Model
{
    protected $table = 'waitlist';

    protected $fillable = [
        'name', 'email', 'budget', 'city',
        'position', 'referral_code', 'referred_by_code',
        'referral_count', 'invited', 'invited_at',
    ];

    protected $casts = [
        'invited'    => 'boolean',
        'invited_at' => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    /**
     * The waitlist entry that referred this person.
     */
    public function referrer()
    {
        return $this->belongsTo(Waitlist::class, 'referred_by_code', 'referral_code');
    }

    /**
     * People this entry has referred.
     */
    public function referrals()
    {
        return $this->hasMany(Waitlist::class, 'referred_by_code', 'referral_code');
    }
}
