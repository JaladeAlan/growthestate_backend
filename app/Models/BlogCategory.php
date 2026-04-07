<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

// ─────────────────────────────────────────────────────────────────────────────
// BlogCategory
// ─────────────────────────────────────────────────────────────────────────────

class BlogCategory extends Model
{
    protected $fillable = ['name', 'slug', 'description'];

    public function posts()
    {
        return $this->hasMany(BlogPost::class, 'category_id');
    }

    // Auto-generate slug when creating
    protected static function booted(): void
    {
        static::creating(function ($category) {
            if (empty($category->slug)) {
                $category->slug = Str::slug($category->name);
            }
        });
    }
}