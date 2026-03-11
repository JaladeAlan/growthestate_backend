<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortfolioLandSnapshot extends Model
{
    protected $table = 'portfolio_land_snapshots';

    public $timestamps = false; // immutable

    protected $fillable = [
        'user_id',
        'land_id',
        'snapshot_date',
        'units_owned',
        'invested_kobo',
        'land_value_kobo',
        'profit_loss_kobo',
        'created_at',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function land(): BelongsTo
    {
        return $this->belongsTo(Land::class);
    }
}
