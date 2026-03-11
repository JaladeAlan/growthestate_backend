<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LandImage extends Model
{
    use HasFactory;

    protected $fillable = ['land_id', 'image_path'];

    protected $appends = ['image_url'];

    public function getImageUrlAttribute()
    {
        if (!$this->image_path) {
            return null;
        }

        // If already full URL
        if (filter_var($this->image_path, FILTER_VALIDATE_URL)) {
            return $this->image_path;
        }

        // Otherwise use storage path
        return asset('storage/' . $this->image_path);
    }

    public function land()
    {
        return $this->belongsTo(Land::class);
    }
}
