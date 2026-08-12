<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $permissions = [
        // Users
        'users.view'          => 'View user accounts',
        'users.suspend'       => 'Suspend a user account',
        'users.unsuspend'     => 'Unsuspend a user account',
        'users.delete'        => 'Delete a user account',
        'roles.manage'        => 'Assign or remove roles from users',

        // KYC
        'kyc.view'            => 'View KYC submissions',
        'kyc.approve'         => 'Approve a KYC submission',
        'kyc.reject'          => 'Reject a KYC submission',

        // Compliance / sanctions screening
        'compliance.view'     => 'View sanctions screening records',
        'compliance.clear'    => 'Clear a flagged screening',
        'compliance.block'    => 'Block a user following screening',
        'compliance.rescreen' => 'Manually re-run screening for a user',

        // Withdrawals
        'withdrawals.view'    => 'View withdrawal requests',
        'withdrawals.approve' => 'Approve a withdrawal request',
        'withdrawals.reject'  => 'Reject a withdrawal request',

        // Lands / catalog
        'lands.manage'        => 'Create, edit, and price land listings',
    ];

    private array $roles = [
        'super_admin' => [
            'label'       => 'Super Admin',
            'description' => 'Full access to all administrative functions.',
            'permissions' => '*', // all permissions
        ],
        'compliance_officer' => [
            'label'       => 'Compliance Officer',
            'description' => 'Handles KYC review and sanctions/PEP screening decisions.',
            'permissions' => [
                'users.view', 'kyc.view', 'kyc.approve', 'kyc.reject',
                'compliance.view', 'compliance.clear', 'compliance.block', 'compliance.rescreen',
            ],
        ],
        'finance_officer' => [
            'label'       => 'Finance Officer',
            'description' => 'Handles withdrawal review and approval.',
            'permissions' => [
                'users.view', 'withdrawals.view', 'withdrawals.approve', 'withdrawals.reject',
            ],
        ],
        'support_agent' => [
            'label'       => 'Support Agent',
            'description' => 'Handles user account support actions.',
            'permissions' => [
                'users.view', 'users.suspend', 'users.unsuspend',
            ],
        ],
    ];

    public function up(): void
    {
        $now = now();

        // ── Permissions ──────────────────────────────────────────────────────
        $permissionIds = [];
        foreach ($this->permissions as $name => $label) {
            $permissionIds[$name] = DB::table('permissions')->insertGetId([
                'name'       => $name,
                'label'      => $label,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // ── Roles + role→permission mapping ─────────────────────────────────
        $roleIds = [];
        foreach ($this->roles as $name => $def) {
            $roleIds[$name] = DB::table('roles')->insertGetId([
                'name'        => $name,
                'label'       => $def['label'],
                'description' => $def['description'],
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);

            $grantedPermissions = $def['permissions'] === '*'
                ? array_keys($this->permissions)
                : $def['permissions'];

            $rows = array_map(fn ($permName) => [
                'role_id'       => $roleIds[$name],
                'permission_id' => $permissionIds[$permName],
            ], $grantedPermissions);

            DB::table('permission_role')->insert($rows);
        }

        // ── Migrate existing is_admin=true users to super_admin ─────────────
        $existingAdmins = DB::table('users')->where('is_admin', true)->pluck('id');

        if ($existingAdmins->isNotEmpty()) {
            DB::table('role_user')->insert(
                $existingAdmins->map(fn ($userId) => [
                    'user_id'     => $userId,
                    'role_id'     => $roleIds['super_admin'],
                    'assigned_at' => $now,
                ])->all()
            );
        }
    }

    public function down(): void
    {
        DB::table('role_user')->delete();
        DB::table('permission_role')->delete();
        DB::table('roles')->delete();
        DB::table('permissions')->delete();
    }
};
