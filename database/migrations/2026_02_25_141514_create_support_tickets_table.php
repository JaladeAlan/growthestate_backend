<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            // Nullable so guests (no account) can also submit tickets
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('guest_name',  100)->nullable();
            $table->string('guest_email', 150)->nullable();
            $table->string('reference', 20)->unique();      // TKT-XXXXXXXX
            $table->string('subject', 150);
            $table->enum('category', ['account', 'payment', 'kyc', 'investment', 'withdrawal', 'other'])
                  ->default('other');
            $table->enum('status', ['open', 'waiting', 'resolved', 'closed'])
                  ->default('open');
            $table->enum('priority', ['low', 'normal', 'high'])->default('normal');
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')
                  ->constrained('support_tickets')
                  ->cascadeOnDelete();
            $table->enum('sender_type', ['user', 'agent', 'bot']);
            $table->unsignedBigInteger('sender_id')->nullable(); // null for bot
            $table->text('body');
            $table->string('attachment_path')->nullable();       // storage/public path
            $table->timestamps();

            $table->index('ticket_id');
        });

        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->string('category', 60)->default('general');
            $table->string('question');
            $table->text('answer');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('faqs');
    }
};