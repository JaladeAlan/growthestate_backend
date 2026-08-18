<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `is_suspended` has two independent writers — AdminUserController's
     * generic suspend/unsuspend, and the compliance flow (auto-block via
     * SanctionsScreeningService::escalate(), or manual block via
     * ComplianceController::block()) — with no way to tell which one is
     * responsible. That meant ComplianceController::clear() couldn't safely
     * reverse a compliance-caused suspension: doing so unconditionally risks
     * reactivating an account suspended for an unrelated reason (fraud,
     * ToS violation) via the generic admin endpoint.
     *
     * suspended_by_compliance lets clear() reverse only the suspension it
     * (or an auto-block) caused, leaving any independent admin suspension
     * untouched.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('suspended_by_compliance')->default(false)->after('is_suspended');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('suspended_by_compliance');
        });
    }
};
