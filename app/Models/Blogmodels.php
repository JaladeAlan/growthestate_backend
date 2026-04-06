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


// ─────────────────────────────────────────────────────────────────────────────
// BlogTag
// ─────────────────────────────────────────────────────────────────────────────

class BlogTag extends Model
{
    protected $fillable = ['name', 'slug'];

    public function posts()
    {
        return $this->belongsToMany(BlogPost::class, 'blog_post_tag', 'blog_tag_id', 'blog_post_id');
    }

    protected static function booted(): void
    {
        static::creating(function ($tag) {
            if (empty($tag->slug)) {
                $tag->slug = Str::slug($tag->name);
            }
        });
    }
}


// ─────────────────────────────────────────────────────────────────────────────
// BlogPost
// ─────────────────────────────────────────────────────────────────────────────

class BlogPost extends Model
{
    protected $fillable = [
        'author_id', 'category_id',
        'title', 'slug', 'excerpt', 'content', 'cover_image',
        'status', 'published_at',
        'seo_title', 'seo_description',
        'read_time_minutes', 'views',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'views'        => 'integer',
    ];

    protected $appends = ['cover_image_url'];

    // ── Relationships ─────────────────────────────────────────────────────────

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function category()
    {
        return $this->belongsTo(BlogCategory::class, 'category_id');
    }

    public function tags()
    {
        return $this->belongsToMany(BlogTag::class, 'blog_post_tag', 'blog_post_id', 'blog_tag_id');
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getCoverImageUrlAttribute(): ?string
    {
        if (! $this->cover_image) return null;

        // If it's already a full URL (e.g. Cloudinary), return as-is
        if (str_starts_with($this->cover_image, 'http')) {
            return $this->cover_image;
        }

        return \Storage::url($this->cover_image);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopePublished(Builder $q): Builder
    {
        return $q->where('status', 'published')
                 ->where('published_at', '<=', now());
    }

    public function scopeForCategory(Builder $q, string $slug): Builder
    {
        return $q->whereHas('category', fn ($c) => $c->where('slug', $slug));
    }

    public function scopeForTag(Builder $q, string $slug): Builder
    {
        return $q->whereHas('tags', fn ($t) => $t->where('slug', $slug));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Estimate read time from plain-text word count.
     * Called before saving when content changes.
     */
    public static function estimateReadTime(string $content): int
    {
        $words = str_word_count(strip_tags($content));
        return max(1, (int) ceil($words / 200)); // 200 wpm average
    }

    // ── Booted ────────────────────────────────────────────────────────────────

    protected static function booted(): void
    {
        static::creating(function ($post) {
            if (empty($post->slug)) {
                $post->slug = Str::slug($post->title);
            }
            if (empty($post->read_time_minutes) && $post->content) {
                $post->read_time_minutes = static::estimateReadTime($post->content);
            }
        });

        static::updating(function ($post) {
            if ($post->isDirty('content')) {
                $post->read_time_minutes = static::estimateReadTime($post->content);
            }
        });
    }
}