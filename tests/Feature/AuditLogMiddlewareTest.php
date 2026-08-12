<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

it('logs an admin mutation to the audit channel with actor, ip, and status', function () {
    Log::shouldReceive('channel')
        ->with('audit')
        ->once()
        ->andReturnSelf();

    Log::shouldReceive('info')
        ->once()
        ->with('sensitive_request', Mockery::on(function ($context) {
            return $context['method'] === 'POST'
                && $context['is_admin'] === true
                && array_key_exists('user_id', $context)
                && array_key_exists('ip', $context)
                && array_key_exists('status', $context);
        }));

    $admin = User::factory()->create([
        'email_verified_at' => now(),
        'is_admin'          => true,
    ]);

    $target = User::factory()->create([
        'email_verified_at' => now(),
        'transaction_pin'   => Hash::make('1234'),
    ]);

    $this->actingAs($admin, 'api')
        ->patchJson("/api/admin/users/{$target->id}/suspend", ['reason' => 'test']);
});

it('does not log GET requests', function () {
    Log::shouldReceive('channel')->never();

    $admin = User::factory()->create([
        'email_verified_at' => now(),
        'is_admin'          => true,
    ]);

    $this->actingAs($admin, 'api')->getJson('/api/admin/users');
});
