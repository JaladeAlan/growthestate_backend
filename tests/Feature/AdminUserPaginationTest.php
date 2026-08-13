<?php

use App\Models\Role;
use App\Models\User;

// ─────────────────────────────────────────────────────────────────────────────
// AdminUserController::index — pagination
//
// per_page=0 used to bypass pagination entirely (`$query->get()` instead of
// `$query->paginate()`), returning every matching user in one response. See
// backend todo #17.
// ─────────────────────────────────────────────────────────────────────────────

function paginationAdminUser(): User
{
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'is_admin'          => false,
    ]);

    $role = Role::where('name', 'support_agent')->firstOrFail();
    $user->roles()->attach($role->id, ['assigned_at' => now()]);

    return $user;
}

it('rejects per_page=0 instead of returning every user unpaginated', function () {
    $admin = paginationAdminUser();
    User::factory()->count(5)->create();

    $this->actingAs($admin, 'api')
        ->getJson('/api/admin/users?per_page=0')
        ->assertStatus(422)
        ->assertJsonValidationErrors('per_page');
});

it('paginates admin user listing at the requested per_page', function () {
    $admin = paginationAdminUser();
    User::factory()->count(15)->create();

    $response = $this->actingAs($admin, 'api')
        ->getJson('/api/admin/users?per_page=10')
        ->assertStatus(200);

    $data = $response->json('data');

    expect($data['data'])->toHaveCount(10);
    expect($data)->toHaveKey('total');
    expect($data)->toHaveKey('current_page');
});

it('defaults to a paginated response when per_page is omitted', function () {
    $admin = paginationAdminUser();
    User::factory()->count(25)->create();

    $response = $this->actingAs($admin, 'api')
        ->getJson('/api/admin/users')
        ->assertStatus(200);

    $data = $response->json('data');

    // Default per_page is 20 — response must stay paginated, not return
    // every one of the 26 users (25 + admin) created above in one page.
    expect($data['data'])->toHaveCount(20);
    expect($data['total'])->toBeGreaterThanOrEqual(26);
});
