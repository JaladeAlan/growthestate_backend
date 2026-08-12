<?php

use App\Models\AdminActionLog;
use App\Models\KycVerification;
use App\Models\Role;
use App\Models\User;
use App\Models\UserScreening;
use App\Models\Withdrawal;

// ─────────────────────────────────────────────────────────────────────────────
// Helpers — prefixed with rbac_ to avoid global function name collisions
// with other test files
// ─────────────────────────────────────────────────────────────────────────────

function rbacStaffUser(string $roleName): User
{
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'is_admin'          => false,
    ]);

    $role = Role::where('name', $roleName)->firstOrFail();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);

    return $user;
}

function rbacPlainUser(): User
{
    return User::factory()->create([
        'email_verified_at' => now(),
        'is_admin'          => false,
    ]);
}

function rbacLegacyAdmin(): User
{
    return User::factory()->create([
        'email_verified_at' => now(),
        'is_admin'          => true,
    ]);
}

function rbacFlaggedScreening(): UserScreening
{
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'screening_status'  => 'flagged',
    ]);

    return UserScreening::create([
        'user_id' => $user->id,
        'status'  => 'flagged',
        'trigger' => 'kyc',
        'matches' => [['source' => 'ofac', 'full_name' => 'A Bad Actor']],
    ]);
}

function rbacPendingKyc(): KycVerification
{
    $user = User::factory()->create(['email_verified_at' => now()]);

    return KycVerification::create([
        'user_id'       => $user->id,
        'full_name'     => $user->name,
        'date_of_birth' => '1990-01-01',
        'phone_number'  => '08012345678',
        'address'       => '1 Test Street',
        'city'          => 'Lagos',
        'state'         => 'Lagos',
        'id_type'       => 'nin',
        'id_number'     => '12345678901',
        'selfie_path'   => 'kyc/selfies/test.jpg',
        'status'        => 'pending',
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Permission-scoped access
// ─────────────────────────────────────────────────────────────────────────────

describe('RBAC permission scoping', function () {

    it('lets a compliance_officer approve KYC without the legacy is_admin flag', function () {
        $officer = rbacStaffUser('compliance_officer');
        $kyc     = rbacPendingKyc();

        $this->actingAs($officer, 'api')
            ->postJson("/api/admin/kyc/{$kyc->id}/approve")
            ->assertStatus(200);
    });

    it('blocks a compliance_officer from approving withdrawals', function () {
        $officer    = rbacStaffUser('compliance_officer');
        $withdrawal = Withdrawal::create([
            'user_id'     => User::factory()->create(['email_verified_at' => now()])->id,
            'amount_kobo' => 1_000_000,
            'status'      => 'pending',
            'reference'   => 'WD-RBAC-TEST',
        ]);

        $this->actingAs($officer, 'api')
            ->postJson("/api/admin/withdrawals/{$withdrawal->id}/approve")
            ->assertStatus(403)
            ->assertJsonPath('code', 'INSUFFICIENT_PERMISSIONS');
    });

    it('lets a finance_officer approve withdrawals without the legacy is_admin flag', function () {
        \Illuminate\Support\Facades\Http::fake([
            'api.paystack.co/transferrecipient' => \Illuminate\Support\Facades\Http::response([
                'status' => true,
                'data'   => ['recipient_code' => 'RCP_test123'],
            ], 200),
            'api.paystack.co/transfer' => \Illuminate\Support\Facades\Http::response([
                'status' => true,
                'data'   => ['transfer_code' => 'TRF_test456'],
            ], 200),
        ]);

        $officer    = rbacStaffUser('finance_officer');
        $withdrawal = Withdrawal::create([
            'user_id'     => User::factory()->create(['email_verified_at' => now(), 'balance_kobo' => 0])->id,
            'amount_kobo' => 1_000_000,
            'status'      => 'pending',
            'reference'   => 'WD-RBAC-TEST-2',
        ]);

        $this->actingAs($officer, 'api')
            ->postJson("/api/admin/withdrawals/{$withdrawal->id}/approve")
            ->assertStatus(200);
    });

    it('blocks a finance_officer from approving KYC', function () {
        $officer = rbacStaffUser('finance_officer');
        $kyc     = rbacPendingKyc();

        $this->actingAs($officer, 'api')
            ->postJson("/api/admin/kyc/{$kyc->id}/approve")
            ->assertStatus(403)
            ->assertJsonPath('code', 'INSUFFICIENT_PERMISSIONS');
    });

    it('lets a support_agent suspend a user but not delete one', function () {
        $agent  = rbacStaffUser('support_agent');
        $target = User::factory()->create(['email_verified_at' => now(), 'is_suspended' => false]);

        $this->actingAs($agent, 'api')
            ->patchJson("/api/admin/users/{$target->id}/suspend")
            ->assertStatus(200);

        $this->actingAs($agent, 'api')
            ->deleteJson("/api/admin/users/{$target->id}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'INSUFFICIENT_PERMISSIONS');
    });

    it("denies a plain user (no role, no is_admin) access to the admin prefix entirely", function () {
        $user = rbacPlainUser();

        $this->actingAs($user, 'api')
            ->getJson('/api/admin/users')
            ->assertStatus(403);
    });

    it('preserves full access for a legacy is_admin=true account with no roles assigned', function () {
        \Illuminate\Support\Facades\Http::fake([
            'api.paystack.co/transferrecipient' => \Illuminate\Support\Facades\Http::response([
                'status' => true,
                'data'   => ['recipient_code' => 'RCP_test123'],
            ], 200),
            'api.paystack.co/transfer' => \Illuminate\Support\Facades\Http::response([
                'status' => true,
                'data'   => ['transfer_code' => 'TRF_test456'],
            ], 200),
        ]);

        $admin = rbacLegacyAdmin();
        $kyc   = rbacPendingKyc();

        $this->actingAs($admin, 'api')
            ->postJson("/api/admin/kyc/{$kyc->id}/approve")
            ->assertStatus(200);

        $withdrawal = Withdrawal::create([
            'user_id'     => User::factory()->create(['email_verified_at' => now(), 'balance_kobo' => 0])->id,
            'amount_kobo' => 1_000_000,
            'status'      => 'pending',
            'reference'   => 'WD-RBAC-LEGACY',
        ]);

        $this->actingAs($admin, 'api')
            ->postJson("/api/admin/withdrawals/{$withdrawal->id}/approve")
            ->assertStatus(200);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Role assignment / revocation
// ─────────────────────────────────────────────────────────────────────────────

describe('Role assignment and revocation', function () {

    it('grants access immediately after a role is assigned (cache is busted)', function () {
        $superAdmin = rbacLegacyAdmin();
        $user       = rbacPlainUser();
        $kyc        = rbacPendingKyc();

        // Before assignment: no access
        $this->actingAs($user, 'api')
            ->postJson("/api/admin/kyc/{$kyc->id}/approve")
            ->assertStatus(403);

        $this->actingAs($superAdmin, 'api')
            ->postJson("/api/admin/users/{$user->id}/roles", ['role' => 'compliance_officer'])
            ->assertStatus(200);

        // Immediately after: access granted, no stale cache
        $this->actingAs($user, 'api')
            ->postJson("/api/admin/kyc/{$kyc->id}/approve")
            ->assertStatus(200);
    });

    it('revokes access immediately after a role is removed (cache is busted)', function () {
        $superAdmin = rbacLegacyAdmin();
        $user       = rbacStaffUser('compliance_officer');
        $role       = Role::where('name', 'compliance_officer')->firstOrFail();

        $this->actingAs($superAdmin, 'api')
            ->deleteJson("/api/admin/users/{$user->id}/roles/{$role->id}")
            ->assertStatus(200);

        $kyc = rbacPendingKyc();

        $this->actingAs($user, 'api')
            ->postJson("/api/admin/kyc/{$kyc->id}/approve")
            ->assertStatus(403);
    });

    it('rejects assigning a role the user already has', function () {
        $superAdmin = rbacLegacyAdmin();
        $user       = rbacStaffUser('compliance_officer');

        $this->actingAs($superAdmin, 'api')
            ->postJson("/api/admin/users/{$user->id}/roles", ['role' => 'compliance_officer'])
            ->assertStatus(422);
    });

    it('only a user with roles.manage can assign roles', function () {
        $agent = rbacStaffUser('support_agent'); // no roles.manage permission
        $user  = rbacPlainUser();

        $this->actingAs($agent, 'api')
            ->postJson("/api/admin/users/{$user->id}/roles", ['role' => 'compliance_officer'])
            ->assertStatus(403);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Admin action audit logging
// ─────────────────────────────────────────────────────────────────────────────

describe('Admin action audit logging', function () {

    it('logs a KYC approval', function () {
        $admin = rbacLegacyAdmin();
        $kyc   = rbacPendingKyc();

        $this->actingAs($admin, 'api')
            ->postJson("/api/admin/kyc/{$kyc->id}/approve")
            ->assertStatus(200);

        $this->assertDatabaseHas('admin_action_logs', [
            'admin_id'    => $admin->id,
            'action'      => 'kyc.approve',
            'target_type' => 'KycVerification',
            'target_id'   => $kyc->id,
        ]);
    });

    it('logs a user suspension', function () {
        $admin  = rbacLegacyAdmin();
        $target = User::factory()->create(['email_verified_at' => now(), 'is_suspended' => false]);

        $this->actingAs($admin, 'api')
            ->patchJson("/api/admin/users/{$target->id}/suspend")
            ->assertStatus(200);

        $this->assertDatabaseHas('admin_action_logs', [
            'admin_id'    => $admin->id,
            'action'      => 'users.suspend',
            'target_type' => 'User',
            'target_id'   => $target->id,
        ]);
    });

    it('logs a compliance block with the reviewer notes', function () {
        $admin     = rbacLegacyAdmin();
        $screening = rbacFlaggedScreening();

        $this->actingAs($admin, 'api')
            ->postJson("/api/admin/compliance/screenings/{$screening->id}/block", [
                'notes' => 'Confirmed exact match against OFAC SDN list.',
            ])
            ->assertStatus(200);

        $log = AdminActionLog::where('action', 'compliance.block')
            ->where('target_id', $screening->id)
            ->first();

        expect($log)->not->toBeNull();
        expect($log->meta['notes'])->toBe('Confirmed exact match against OFAC SDN list.');
    });

    it('logs a withdrawal rejection with the reason', function () {
        $admin      = rbacLegacyAdmin();
        $withdrawal = Withdrawal::create([
            'user_id'     => User::factory()->create(['email_verified_at' => now()])->id,
            'amount_kobo' => 1_000_000,
            'status'      => 'pending',
            'reference'   => 'WD-RBAC-AUDIT',
        ]);

        $this->actingAs($admin, 'api')
            ->postJson("/api/admin/withdrawals/{$withdrawal->id}/reject", [
                'reason' => 'Suspicious activity detected.',
            ])
            ->assertStatus(200);

        $log = AdminActionLog::where('action', 'withdrawal.reject')
            ->where('target_id', $withdrawal->id)
            ->first();

        expect($log)->not->toBeNull();
        expect($log->meta['reason'])->toBe('Suspicious activity detected.');
    });
});
