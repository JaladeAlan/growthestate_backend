<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserLand extends Model
{
    protected $table = 'user_land';

    protected $fillable = [
        'user_id',
        'land_id',
        'units',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function land()
    {
        return $this->belongsTo(Land::class);
    }
}
