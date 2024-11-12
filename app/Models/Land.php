<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Land extends Model
{
    use HasFactory;

    protected $fillable = [
        'title', 'location', 'size', 'price_per_unit', 'total_units', 'available_units', 'is_available', 'description'
    ];

    // Default values for total_units and available_units
    protected $attributes = [
        'total_units' => 0,         // Default to 0 until specified at creation
        'available_units' => 0,     // Matches total_units initially
    ];

    // A land can have many transactions
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    // A land can have many purchases
    public function purchases()
    {
        return $this->hasMany(Purchase::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_land')->withPivot('units')->withTimestamps();
    }

    public function images()
    {
        return $this->hasMany(LandImage::class);
    }

}
