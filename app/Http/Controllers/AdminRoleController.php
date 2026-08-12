<?php

namespace App\Http\Controllers;

use App\Models\AdminActionLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Admin role management.
 *
 * Routes (under /admin prefix + admin middleware + permission:roles.manage):
 *   GET    /admin/roles
 *   GET    /admin/users/{user}/roles
 *   POST   /admin/users/{user}/roles
 *   DELETE /admin/users/{user}/roles/{role}
 */
class AdminRoleController extends Controller
{
    // GET /admin/roles
    public function index()
    {
        return response()->json([
            'success' => true,
            'data'    => Role::with('permissions:id,name,label')->get(),
        ]);
    }

    // GET /admin/users/{user}/roles
    public function userRoles(User $user)
    {
        return response()->json([
            'success' => true,
            'data'    => $user->roles()->with('permissions:id,name,label')->get(),
        ]);
    }

    // POST /admin/users/{user}/roles
    public function assignRole(Request $request, User $user)
    {
        $data = $request->validate([
            'role' => 'required|string|exists:roles,name',
        ]);

        $role = Role::where('name', $data['role'])->firstOrFail();
        $admin = $request->user();

        if ($user->roles()->where('role_id', $role->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'User already has this role.',
            ], 422);
        }

        $user->roles()->attach($role->id, [
            'assigned_at' => now(),
            'assigned_by' => $admin->id,
        ]);

        $this->bustPermissionCache($user, $role);

        AdminActionLog::record(
            $admin,
            'roles.assign',
            'User',
            $user->id,
            ['role' => $role->name],
            $request->ip()
        );

        return response()->json([
            'success' => true,
            'message' => "Role '{$role->label}' assigned.",
        ]);
    }

    // DELETE /admin/users/{user}/roles/{role}
    public function revokeRole(Request $request, User $user, Role $role)
    {
        $admin = $request->user();

        $user->roles()->detach($role->id);
        $this->bustPermissionCache($user, $role);

        AdminActionLog::record(
            $admin,
            'roles.revoke',
            'User',
            $user->id,
            ['role' => $role->name],
            $request->ip()
        );

        return response()->json([
            'success' => true,
            'message' => "Role '{$role->label}' revoked.",
        ]);
    }

    /**
     * hasPermission() caches per user+permission for 5 minutes — bust the
     * specific keys for this role's permissions so a role change takes
     * effect immediately instead of waiting out the cache TTL.
     */
    private function bustPermissionCache(User $user, Role $role): void
    {
        foreach ($role->permissions()->pluck('name') as $permission) {
            Cache::forget("user:{$user->id}:permission:{$permission}");
        }
    }
}
