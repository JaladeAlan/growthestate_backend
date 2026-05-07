<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
 
class UserScreening extends Model
{
    protected $fillable = [
        'user_id', 'status', 'trigger', 'matches',
        'reviewed_by', 'reviewed_at', 'notes',
    ];
 
    protected $casts = [
        'matches'     => 'array',
        'reviewed_at' => 'datetime',
    ];
 
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}