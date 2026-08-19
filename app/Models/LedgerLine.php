<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One side of a double-entry transaction. Always comes in pairs:
 * a debit line (negative amount_kobo) and a credit line (positive
 * amount_kobo) whose amounts sum to zero.
 */
class LedgerLine extends Model
{
    protected $fillable = [
        'ledger_transaction_id',
        'account',
        'amount_kobo',
        'balance_after',
    ];

    protected $casts = [
        'amount_kobo'  => 'integer',
        'balance_after' => 'integer',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'ledger_transaction_id');
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('LedgerLine rows are immutable after creation.');
        });

        static::deleting(function () {
            throw new RuntimeException('LedgerLine rows are immutable after creation.');
        });
    }
}
