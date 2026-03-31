<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();

            // ── Ownership ─────────────────────────────────────────────────
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('land_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_id')->nullable()->constrained()->nullOnDelete();

            // ── Identity ──────────────────────────────────────────────────
            $table->string('cert_number')->unique();      // CERT-2025-KL001-00001
            $table->string('digital_signature', 64);      // SHA-256 hex

            // ── Snapshot of purchase data at issuance time ─────────────────
            $table->string('owner_name');
            $table->integer('units');
            $table->decimal('total_invested', 15, 2);
            $table->string('purchase_reference');

            // ── Property snapshot ──────────────────────────────────────────
            $table->string('property_title');
            $table->string('property_location');
            $table->string('plot_identifier')->nullable();
            $table->string('tenure')->nullable();
            $table->string('lga')->nullable();
            $table->string('state')->nullable();

            // ── Storage ───────────────────────────────────────────────────
            $table->string('pdf_path')->nullable();    

            // ── Status ────────────────────────────────────────────────────
            $table->enum('status', ['active', 'revoked'])->default('active');
            $table->timestamp('issued_at');
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            // ── Indexes ───────────────────────────────────────────────────
            $table->index(['user_id', 'status']);
            $table->index(['land_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};