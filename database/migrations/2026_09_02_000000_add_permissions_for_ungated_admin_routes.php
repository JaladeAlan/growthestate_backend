<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Several admin route groups (lands, support tickets, live chat, blog,
 * referrals, certificates, waitlist) were only protected by the coarse
 * AdminMiddleware ("has any role") and had no `permission:` middleware,
 * so any staff account with even one assigned role could fully manage
 * them regardless of what that role was actually meant to grant.
 *
 * This migration adds the missing permissions and:
 *   - grants all of them to super_admin (kept in sync explicitly, since
 *     the '*' shorthand in the original seeder only applied at seed time)
 *   - grants the support/live-chat permissions to support_agent, since
 *     that's squarely their existing job description
 *   - creates a new operations_manager role for the remaining ungated
 *     domains (lands, blog, referrals, certificates, waitlist), since no
 *     existing role covers that combination
 *
 * The routes themselves are wired to these permissions in a follow-up
 * change to routes/api_routes.php — this migration only creates the data.
 */
return new class extends Migration
{
    private array $permissions = [
        'lands.manage'          => 'Create, edit, and price land listings',
        'support.tickets.view'  => 'View support tickets',
        'support.tickets.manage'=> 'Reply to, update, and close support tickets',
        'live_chat.manage'      => 'Handle live chat queue and agent actions',
        'blog.manage'           => 'Create, edit, and publish blog posts',
        'referrals.view'        => 'View referral stats and records',
        'certificates.view'     => 'View issued certificates',
        'certificates.manage'   => 'Revoke and regenerate certificates',
        'waitlist.view'         => 'View waitlist entries and stats',
        'waitlist.manage'       => 'Invite and remove waitlist entries',
    ];

    public function up(): void
    {
        $now = now();

        // ── lands.manage may already exist from the original seeder ────────
        $permissionIds = [];
        foreach ($this->permissions as $name => $label) {
            $existing = DB::table('permissions')->where('name', $name)->first();

            $permissionIds[$name] = $existing
                ? $existing->id
                : DB::table('permissions')->insertGetId([
                    'name'       => $name,
                    'label'      => $label,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
        }

        // ── Grant everything to super_admin ─────────────────────────────────
        $superAdminId = DB::table('roles')->where('name', 'super_admin')->value('id');

        if ($superAdminId) {
            foreach ($permissionIds as $permissionId) {
                $exists = DB::table('permission_role')
                    ->where('role_id', $superAdminId)
                    ->where('permission_id', $permissionId)
                    ->exists();

                if (! $exists) {
                    DB::table('permission_role')->insert([
                        'role_id'       => $superAdminId,
                        'permission_id' => $permissionId,
                        'created_at'    => $now,
                    ]);
                }
            }
        }

        // ── Grant support/live-chat permissions to support_agent ───────────
        $supportAgentId = DB::table('roles')->where('name', 'support_agent')->value('id');

        if ($supportAgentId) {
            foreach (['support.tickets.view', 'support.tickets.manage', 'live_chat.manage'] as $permName) {
                DB::table('permission_role')->insert([
                    'role_id'       => $supportAgentId,
                    'permission_id' => $permissionIds[$permName],
                    'created_at'    => $now,
                ]);
            }
        }

        // ── New role: operations_manager (lands, blog, referrals, ──────────
        //    certificates, waitlist) — nothing previously covered this.
        $opsManagerId = DB::table('roles')->insertGetId([
            'name'        => 'operations_manager',
            'label'       => 'Operations Manager',
            'description' => 'Manages land listings, blog content, referrals, certificates, and the waitlist.',
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        foreach ([
            'lands.manage', 'blog.manage', 'referrals.view',
            'certificates.view', 'certificates.manage',
            'waitlist.view', 'waitlist.manage',
        ] as $permName) {
            DB::table('permission_role')->insert([
                'role_id'       => $opsManagerId,
                'permission_id' => $permissionIds[$permName],
                'created_at'    => $now,
            ]);
        }
    }

    public function down(): void
    {
        $opsManagerId = DB::table('roles')->where('name', 'operations_manager')->value('id');

        if ($opsManagerId) {
            DB::table('permission_role')->where('role_id', $opsManagerId)->delete();
            DB::table('role_user')->where('role_id', $opsManagerId)->delete();
            DB::table('roles')->where('id', $opsManagerId)->delete();
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('name', array_keys($this->permissions))
            ->pluck('id');

        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
