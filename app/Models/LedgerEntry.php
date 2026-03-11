<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function user()
    {
        return $this->belongsTo(User::class, 'uid');
    }
}