<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = ['land_id', 'user_id', 'units', 'price', 'status',  'reference'];


    // A transaction belongs to a land
    public function land()
    {
        return $this->belongsTo(Land::class);
    }

    // A transaction belongs to a user
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
