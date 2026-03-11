<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Deposit extends Model
{
    use HasFactory;

    const STATUS_PENDING   = 'pending';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED    = 'failed';

    // ── Table / fillable ──────────────────────────────────────────────────────

    protected $table = 'deposits';

    protected $fillable = [
        'user_id',
        'reference',
        'amount_kobo',
        'transaction_fee',
        'total_kobo',
        'status',
        'gateway',
        'processed_at',  
    ];

    protected $attributes = [
        'status'          => self::STATUS_PENDING,
        'transaction_fee' => 0,
    ];

    protected $casts = [
        'amount_kobo'     => 'integer',
        'transaction_fee' => 'integer',
        'total_kobo'      => 'integer',
        'processed_at'    => 'datetime', 
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // ── Status helpers ────────────────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeByGateway($query, string $gateway)
    {
        return $query->where('gateway', $gateway);
    }

    // ── Accessors ─────────────────────────────────────────────────────────────
    public function getAmountNairaAttribute(): float
    {
        return $this->amount_kobo / 100;
    }

    public function getTotalNairaAttribute(): float
    {
        return $this->total_kobo / 100;
    }
}