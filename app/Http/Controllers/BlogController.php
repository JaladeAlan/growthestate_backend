<?php

namespace App\Http\Controllers;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BlogController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // PUBLIC
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /blog
     * Paginated list of published posts with optional filtering.
     */
    public function index(Request $request)
    {
        $request->validate([
            'category' => 'nullable|string',
            'tag'      => 'nullable|string',
            'search'   => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:1|max:24',
        ]);

        $q = BlogPost::published()
            ->with(['author:id,name', 'category:id,name,slug', 'tags:id,name,slug'])
            ->select([
                'id', 'author_id', 'category_id',
                'title', 'slug', 'excerpt', 'cover_image',
                'published_at', 'read_time_minutes', 'views',
                'seo_title', 'seo_description',
            ])
            ->orderByDesc('published_at');

        if ($request->category) {
            $q->forCategory($request->category);
        }

        if ($request->tag) {
            $q->forTag($request->tag);
        }

        if ($request->search) {
            $search = '%' . $request->search . '%';
            $q->where(fn ($q) =>
                $q->where('title', 'ilike', $search)
                  ->orWhere('excerpt', 'ilike', $search)
            );
        }

        return response()->json([
            'success' => true,
            'data'    => $q->paginate($request->per_page ?? 9),
        ]);
    }

    /**
     * GET /blog/{slug}
     * Single post — increments view count.
     */
    public function show(string $slug)
    {
        $post = BlogPost::published()
            ->with(['author:id,name', 'category:id,name,slug', 'tags:id,name,slug'])
            ->where('slug', $slug)
            ->firstOrFail();

        // Increment views (non-blocking — best effort)
        $post->incrementQuietly('views');

        return response()->json(['success' => true, 'data' => $post]);
    }

    /**
     * GET /blog/categories
     * All categories with published post counts.
     */
    public function categories()
    {
        $categories = BlogCategory::withCount(['posts' => fn ($q) => $q->published()])
            ->having('posts_count', '>', 0)
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'data' => $categories]);
    }

    /**
     * GET /blog/tags
     * All tags with published post counts.
     */
    public function tags()
    {
        $tags = BlogTag::withCount(['posts' => fn ($q) => $q->published()])
            ->having('posts_count', '>', 0)
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'data' => $tags]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ADMIN — POSTS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /admin/blog
     * All posts (drafts + published), paginated.
     */
    public function adminIndex(Request $request)
    {
        $q = BlogPost::with(['author:id,name', 'category:id,name,slug', 'tags:id,name,slug'])
            ->orderByDesc('created_at');

        if ($request->status) {
            $q->where('status', $request->status);
        }

        return response()->json([
            'success' => true,
            'data'    => $q->paginate($request->integer('per_page', 20)),
        ]);
    }

    /**
     * GET /admin/blog/{id}
     * Single post (any status) for the editor.
     */
    public function adminShow(BlogPost $blogPost)
    {
        return response()->json([
            'success' => true,
            'data'    => $blogPost->load(['author:id,name', 'category', 'tags']),
        ]);
    }

    /**
     * POST /admin/blog
     * Create a new post (draft by default).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'title'           => 'required|string|max:200',
            'slug'            => 'nullable|string|max:220|unique:blog_posts,slug',
            'excerpt'         => 'nullable|string|max:500',
            'content'         => 'required|string',
            'category_id'     => 'nullable|integer|exists:blog_categories,id',
            'tag_ids'         => 'nullable|array',
            'tag_ids.*'       => 'integer|exists:blog_tags,id',
            'status'          => 'nullable|in:draft,published',
            'published_at'    => 'nullable|date',
            'seo_title'       => 'nullable|string|max:70',
            'seo_description' => 'nullable|string|max:160',
            'cover_image'     => 'nullable|image|max:4096',
        ]);

        $coverPath = null;
        if ($request->hasFile('cover_image')) {
            $coverPath = $request->file('cover_image')->store('blog/covers', 'public');
        }

        $status      = $data['status'] ?? 'draft';
        $publishedAt = $status === 'published'
            ? ($data['published_at'] ?? now())
            : null;

        $post = BlogPost::create([
            'author_id'       => $request->user()->id,
            'title'           => $data['title'],
            'slug'            => $data['slug'] ?? Str::slug($data['title']),
            'excerpt'         => $data['excerpt']         ?? null,
            'content'         => $data['content'],
            'category_id'     => $data['category_id']     ?? null,
            'cover_image'     => $coverPath,
            'status'          => $status,
            'published_at'    => $publishedAt,
            'seo_title'       => $data['seo_title']       ?? null,
            'seo_description' => $data['seo_description'] ?? null,
        ]);

        if (! empty($data['tag_ids'])) {
            $post->tags()->sync($data['tag_ids']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Post created.',
            'data'    => $post->load(['category', 'tags']),
        ], 201);
    }

    /**
     * POST /admin/blog/{id}  (POST for multipart form-data)
     * Update an existing post.
     */
    public function update(Request $request, BlogPost $blogPost)
    {
        $data = $request->validate([
            'title'           => 'sometimes|string|max:200',
            'slug'            => 'sometimes|string|max:220|unique:blog_posts,slug,' . $blogPost->id,
            'excerpt'         => 'nullable|string|max:500',
            'content'         => 'sometimes|string',
            'category_id'     => 'nullable|integer|exists:blog_categories,id',
            'tag_ids'         => 'nullable|array',
            'tag_ids.*'       => 'integer|exists:blog_tags,id',
            'status'          => 'nullable|in:draft,published',
            'published_at'    => 'nullable|date',
            'seo_title'       => 'nullable|string|max:70',
            'seo_description' => 'nullable|string|max:160',
            'cover_image'     => 'nullable|image|max:4096',
        ]);

        // Handle new cover image
        if ($request->hasFile('cover_image')) {
            if ($blogPost->cover_image) {
                Storage::disk('public')->delete($blogPost->cover_image);
            }
            $data['cover_image'] = $request->file('cover_image')->store('blog/covers', 'public');
        }

        // Set published_at when publishing for the first time
        if (
            isset($data['status']) &&
            $data['status'] === 'published' &&
            $blogPost->status === 'draft'
        ) {
            $data['published_at'] = $data['published_at'] ?? now();
        }

        // Revert to null if going back to draft
        if (isset($data['status']) && $data['status'] === 'draft') {
            $data['published_at'] = null;
        }

        $blogPost->update($data);

        if (array_key_exists('tag_ids', $data)) {
            $blogPost->tags()->sync($data['tag_ids'] ?? []);
        }

        return response()->json([
            'success' => true,
            'message' => 'Post updated.',
            'data'    => $blogPost->fresh()->load(['category', 'tags']),
        ]);
    }

    /**
     * DELETE /admin/blog/{id}
     */
    public function destroy(BlogPost $blogPost)
    {
        if ($blogPost->cover_image) {
            Storage::disk('public')->delete($blogPost->cover_image);
        }

        $blogPost->tags()->detach();
        $blogPost->delete();

        return response()->json(['success' => true, 'message' => 'Post deleted.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ADMIN — CATEGORIES & TAGS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /admin/blog/categories
     * All categories regardless of published post count — for the admin manager.
     */
    public function adminCategories()
    {
        $categories = BlogCategory::withCount('posts')
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'data' => $categories]);
    }

    /**
     * GET /admin/blog/tags
     * All tags regardless of published post count — for the admin manager.
     */
    public function adminTags()
    {
        $tags = BlogTag::withCount('posts')
            ->orderBy('name')
            ->get();

        return response()->json(['success' => true, 'data' => $tags]);
    }

    public function storeCategory(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:80',
            'slug'        => 'nullable|string|max:100|unique:blog_categories,slug',
            'description' => 'nullable|string|max:300',
        ]);

        $category = BlogCategory::create($data);

        return response()->json(['success' => true, 'data' => $category], 201);
    }

    public function updateCategory(Request $request, BlogCategory $blogCategory)
    {
        $data = $request->validate([
            'name'        => 'sometimes|string|max:80',
            'slug'        => 'sometimes|string|max:100|unique:blog_categories,slug,' . $blogCategory->id,
            'description' => 'nullable|string|max:300',
        ]);

        $blogCategory->update($data);

        return response()->json(['success' => true, 'data' => $blogCategory->fresh()]);
    }

    public function destroyCategory(BlogCategory $blogCategory)
    {
        // Posts in this category will have category_id set to null (nullOnDelete)
        $blogCategory->delete();

        return response()->json(['success' => true, 'message' => 'Category deleted.']);
    }

    public function storeTag(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:60',
            'slug' => 'nullable|string|max:80|unique:blog_tags,slug',
        ]);

        $tag = BlogTag::create($data);

        return response()->json(['success' => true, 'data' => $tag], 201);
    }

    public function destroyTag(BlogTag $blogTag)
    {
        $blogTag->posts()->detach();
        $blogTag->delete();

        return response()->json(['success' => true, 'message' => 'Tag deleted.']);
    }
}