<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = ['land_id', 'user_id', 'type','units', 'amount_kobo', 'status', 
     'reference', 'message', 'transaction_date'];

    protected $casts = [
        'transaction_date' => 'datetime',
        'amount_kobo'      => 'integer',
    ];


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
