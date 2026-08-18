<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Append-only. Ledger entries are the audit trail for every wallet
 * balance change — they must never be modified or removed after
 * creation. Corrections belong as new offsetting rows (e.g. a
 * 'reversal' or 'adjustment' entry), never as an edit to history.
 *
 * This class-level guard is the app-side backstop; a matching DB
 * trigger (see migration add_append_only_trigger_to_ledger_entries)
 * enforces the same rule against raw SQL, tinker, or anything that
 * bypasses Eloquent.
 */
class LedgerEntry extends Model
{
    protected $fillable = [
        'uid',
        'type',
        'amount_kobo',
        'balance_after',
        'rewards_balance_after',
        'reference',
        'note',
    ];

    protected $casts = [
        'amount_kobo'           => 'integer',
        'balance_after'         => 'integer',
        'rewards_balance_after' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException(
                'LedgerEntry rows are append-only and cannot be updated. Write a new offsetting entry instead.'
            );
        });

        static::deleting(function () {
            throw new RuntimeException(
                'LedgerEntry rows are append-only and cannot be deleted. Write a new offsetting entry instead.'
            );
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'uid');
    }
}