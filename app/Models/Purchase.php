<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Purchase extends Model
{
    use HasFactory;

    // The attributes that are mass assignable
    protected $fillable = [
        'user_id',
        'land_id',
        'units',                // Number of units purchased
        'total_amount_paid',    // Total amount paid for the purchase
        'purchase_date',        // Date of purchase
        'withdrawal_date',      // Date of withdrawal, if applicable
        'units_sold',           // Number of units sold
        'total_amount_received', // Total amount received from sales
        'reference',
    ];

    // Cast attributes to appropriate data types
    protected $casts = [
      'purchase_date' => 'datetime',
      'withdrawal_date' => 'datetime',
      'units_sold' => 'integer',           
      'total_amount_received' => 'decimal', 
    ];

    // Relationship with the User model
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Relationship with the Land model
    public function land()
    {
        return $this->belongsTo(Land::class);
    }

    // Relationship with the Transaction model (if applicable)
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    // Query scope to get purchases by user
    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}
