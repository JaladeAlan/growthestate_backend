<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Share extends Model
{
    use HasFactory;

    protected $fillable = ['land_id', 'percentage', 'user_id'];

    public function land()
    {
        return $this->belongsTo(Land::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
