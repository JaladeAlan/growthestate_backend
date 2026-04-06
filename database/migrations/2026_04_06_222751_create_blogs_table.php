<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Categories ────────────────────────────────────────────────────────
        Schema::create('blog_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('description')->nullable();
            $table->timestamps();
        });

        // ── Tags ──────────────────────────────────────────────────────────────
        Schema::create('blog_tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        // ── Posts ─────────────────────────────────────────────────────────────
        Schema::create('blog_posts', function (Blueprint $table) {
            $table->id();

            // Authorship
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')
                  ->nullable()
                  ->constrained('blog_categories')
                  ->nullOnDelete();

            // Content
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('excerpt')->nullable();       // short summary shown on listing
            $table->longText('content');               // HTML / markdown body
            $table->string('cover_image')->nullable(); // storage path

            // Status
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->timestamp('published_at')->nullable();

            // SEO
            $table->string('seo_title')->nullable();
            $table->text('seo_description')->nullable();

            // Metrics
            $table->unsignedInteger('read_time_minutes')->default(1);
            $table->unsignedBigInteger('views')->default(0);

            $table->timestamps();

            $table->index(['status', 'published_at']);
            $table->index('author_id');
            $table->index('category_id');
        });

        // ── Post ↔ Tag pivot ──────────────────────────────────────────────────
        Schema::create('blog_post_tag', function (Blueprint $table) {
            $table->foreignId('blog_post_id')
                  ->constrained('blog_posts')
                  ->cascadeOnDelete();
            $table->foreignId('blog_tag_id')
                  ->constrained('blog_tags')
                  ->cascadeOnDelete();
            $table->primary(['blog_post_id', 'blog_tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_post_tag');
        Schema::dropIfExists('blog_posts');
        Schema::dropIfExists('blog_tags');
        Schema::dropIfExists('blog_categories');
    }
};