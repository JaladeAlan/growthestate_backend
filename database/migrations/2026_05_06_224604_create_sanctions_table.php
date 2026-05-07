<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void
    {
        // ── Sanctions list entries (downloaded from OFAC, UN, EU) ──
        Schema::create('sanctions_entries', function (Blueprint $table) {
            $table->id();
            $table->string('source');                    // ofac | un | eu
            $table->string('source_id')->nullable();     // original ID from the list
            $table->string('entry_type');                // individual | entity | vessel | aircraft
            $table->string('full_name');
            $table->string('full_name_normalized');      // lowercase, no diacritics — for fast matching
            $table->json('aliases')->nullable();          // other names
            $table->json('aliases_normalized')->nullable();
            $table->date('dob')->nullable();
            $table->string('nationality')->nullable();
            $table->string('program')->nullable();       // sanctions program e.g. SDGT, IRAN
            $table->boolean('is_pep')->default(false);
            $table->json('raw')->nullable();             // full raw record for audit
            $table->timestamps();

            $table->index('full_name_normalized');
            $table->index('source');
            $table->index('is_pep');
            $table->unique(['source', 'source_id']);
        });

        // ── Screening results per user ─────────────────────────────────────────
        Schema::create('user_screenings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['clear', 'flagged', 'blocked', 'manual_review']);
            $table->string('trigger')->nullable();       // registration | kyc | scheduled | manual
            $table->json('matches')->nullable();         // matched sanctions_entries ids + scores
            $table->string('reviewed_by')->nullable();   // admin who reviewed
            $table->timestamp('reviewed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        // ── Track list download history ────────────────────────────────────────
        Schema::create('sanctions_list_syncs', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->enum('status', ['success', 'failed']);
            $table->integer('records_imported')->default(0);
            $table->integer('records_deleted')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('synced_at');
        });

        // ── Add screening columns to users ─────────────────────────────────────
        Schema::table('users', function (Blueprint $table) {
            $table->enum('screening_status', ['pending', 'clear', 'flagged', 'blocked'])
                  ->default('pending')
                  ->after('kyc_status');
            $table->timestamp('last_screened_at')->nullable()->after('screening_status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['screening_status', 'last_screened_at']);
        });
        Schema::dropIfExists('sanctions_list_syncs');
        Schema::dropIfExists('user_screenings');
        Schema::dropIfExists('sanctions_entries');
    }
};