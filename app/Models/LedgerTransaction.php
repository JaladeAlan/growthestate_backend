<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * Represents a single financial event in the double-entry ledger.
 * Every LedgerTransaction must have exactly two LedgerLine children
 * (a debit and a credit) that net to zero. Use LedgerService to post;
 * do not instantiate directly.
 */
class LedgerTransaction extends Model
{
    protected $fillable = [
        'type',
        'idempotency_key',
        'reference',
        'note',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(LedgerLine::class);
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('LedgerTransaction rows are immutable after creation.');
        });

        static::deleting(function () {
            throw new RuntimeException('LedgerTransaction rows are immutable after creation.');
        });
    }
}
