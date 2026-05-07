<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('kyc_verifications', function (Blueprint $table) {
            $table->boolean('is_pep')->default(false)->after('address');
            $table->enum('pep_relationship', ['self', 'family', 'associate'])
                  ->nullable()
                  ->after('is_pep');
            $table->string('pep_role', 100)->nullable()->after('pep_relationship');
            $table->char('pep_country', 2)->nullable()->after('pep_role');
            $table->text('pep_details')->nullable()->after('pep_country');
        });
    }

    public function down(): void
    {
        Schema::table('kyc_verifications', function (Blueprint $table) {
            $table->dropColumn(['is_pep', 'pep_relationship', 'pep_role', 'pep_country', 'pep_details']);
        });
    }
};