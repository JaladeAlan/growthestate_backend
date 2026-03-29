<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LandValuation extends Model
{
    protected $fillable = ['land_id', 'year', 'month', 'value'];

    protected $casts = [
        'year'  => 'integer',
        'month' => 'integer',
        'value' => 'float',
    ];

    public function land()
    {
        return $this->belongsTo(Land::class);
    }
}