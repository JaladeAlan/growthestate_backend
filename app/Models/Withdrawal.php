<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Withdrawal extends Model
{
    protected $fillable = [
        'user_id',
        'amount_kobo',
        'status',
        'reference',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
    ];

    protected $casts = [
        'amount_kobo' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    // RELATIONSHIPS
    // ─────────────────────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SCOPES
    // ─────────────────────────────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ACCESSORS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Amount in Naira for display purposes.
     */
    public function getAmountNairaAttribute(): float
    {
        return $this->amount_kobo / 100;
    }

    /**
     * Whether this withdrawal can still be approved by an admin.
     */
    public function getIsApprovableAttribute(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Whether this withdrawal can be rejected (refunded) by an admin.
     * Failed withdrawals can also be rejected once admin verifies on Paystack
     * that the transfer did not go through.
     */
    public function getIsRejectableAttribute(): bool
    {
        return in_array($this->status, ['pending', 'failed'], true);
    }
}