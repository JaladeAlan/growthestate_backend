<?php

use App\Models\User;
use App\Models\Referral;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

// ─────────────────────────────────────────────────────────────────────────────
// Companion to LoginTest.php / AuthSessionLifecycleTest.php — covers the
// remaining AuthController endpoints: register, email verification (verify +
// resend), and the three-step password reset flow. Queue::fake()/Mail::fake()
// are used throughout, matching the pattern in KycTest/PinRateLimitTest,
// since register() dispatches ScreenUserJob and MailService::queue() would
// otherwise attempt a real outbound send via whichever provider
// resolveMailer() picks (QUEUE_CONNECTION=sync in phpunit.xml means that
// isn't actually deferred without faking it).
// ─────────────────────────────────────────────────────────────────────────────

const STRONG_PASSWORD = 'Passw0rd!';

function validRegisterPayload(array $overrides = []): array
{
    return array_merge([
        'name'                  => 'Ada Lovelace',
        'email'                 => 'ada@example.com',
        'password'              => STRONG_PASSWORD,
        'password_confirmation' => STRONG_PASSWORD,
    ], $overrides);
}

// ── REGISTER ──────────────────────────────────────────────────────────────

it('registers a new user and returns a token', function () {
    Queue::fake();
    Mail::fake();

    $response = $this->postJson('/api/register', validRegisterPayload());

    $response->assertStatus(201)
        ->assertJsonPath('message', 'Registration successful. Please check your email for the verification code.')
        ->assertJsonStructure(['data' => ['user', 'token']]);

    $this->assertDatabaseHas('users', ['email' => 'ada@example.com']);

    $user = User::where('email', 'ada@example.com')->first();
    expect($user->hasVerifiedEmail())->toBeFalse();
    expect(Hash::check(STRONG_PASSWORD, $user->password))->toBeTrue();
});

it('dispatches a sanctions screening job on registration', function () {
    Queue::fake();
    Mail::fake();

    $this->postJson('/api/register', validRegisterPayload())->assertStatus(201);

    Queue::assertPushed(\App\Jobs\ScreenUserJob::class);
});

it('queues a verification email on registration', function () {
    Queue::fake();
    Mail::fake();

    $this->postJson('/api/register', validRegisterPayload())->assertStatus(201);

    // MailService::queue() calls Mail::mailer(...)->to(...)->queue(...);
    // Mail::fake() intercepts regardless of which named mailer resolveMailer()
    // picks, so this doesn't need to know which provider was selected.
    Mail::assertQueued(\App\Mail\VerifyEmailMail::class);
});

it('returns a generic success response for an already-registered email, without creating a duplicate', function () {
    Queue::fake();
    Mail::fake();

    User::factory()->create(['email' => 'existing@example.com']);

    $response = $this->postJson('/api/register', validRegisterPayload(['email' => 'existing@example.com']));

    // Same 201 + generic message as a fresh registration — no signal that
    // distinguishes "already registered" from "just registered", which
    // would otherwise let an attacker enumerate accounts.
    $response->assertStatus(201)
        ->assertJsonPath('message', 'If this email is not already registered, check your inbox for a verification code.');

    expect(User::where('email', 'existing@example.com')->count())->toBe(1);
    Mail::assertNothingQueued();
    Queue::assertNothingPushed();
});

it('rejects registration with a weak password', function () {
    $response = $this->postJson('/api/register', validRegisterPayload([
        'password'              => 'alllowercase',
        'password_confirmation' => 'alllowercase',
    ]));

    $response->assertStatus(422)
        ->assertJsonPath('message', 'Validation errors occurred');

    $this->assertDatabaseMissing('users', ['email' => 'ada@example.com']);
});

it('rejects registration when password confirmation does not match', function () {
    $response = $this->postJson('/api/register', validRegisterPayload([
        'password_confirmation' => 'SomethingElse1!',
    ]));

    $response->assertStatus(422);
});

it('links a referral when a valid referral_code is supplied', function () {
    Queue::fake();
    Mail::fake();

    $referrer = User::factory()->create(['referral_code' => 'ADA123']);

    $this->postJson('/api/register', validRegisterPayload(['referral_code' => 'ADA123']))
        ->assertStatus(201);

    $newUser = User::where('email', 'ada@example.com')->first();
    expect($newUser->referred_by)->toBe($referrer->id);

    $this->assertDatabaseHas('referrals', [
        'referrer_id'      => $referrer->id,
        'referred_user_id' => $newUser->id,
        'status'           => 'pending',
    ]);
});

it('rejects registration with a referral_code that does not exist', function () {
    $response = $this->postJson('/api/register', validRegisterPayload(['referral_code' => 'NOT-REAL']));

    $response->assertStatus(422);
    $this->assertDatabaseMissing('users', ['email' => 'ada@example.com']);
});

it('rate limits registration attempts', function () {
    Queue::fake();
    Mail::fake();

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/register', validRegisterPayload(['email' => "user{$i}@example.com"]))
            ->assertStatus(201);
    }

    $response = $this->postJson('/api/register', validRegisterPayload(['email' => 'oneMore@example.com']));

    $response->assertStatus(429);
});

// ── EMAIL VERIFICATION ────────────────────────────────────────────────────

function unverifiedUserWithCode(string $code = '123456'): User
{
    return User::factory()->create([
        'email'                    => 'verifyme@example.com',
        'email_verified_at'        => null,
        'verification_code'        => Hash::make($code),
        'verification_code_expiry' => now()->addMinutes(30),
    ]);
}

it('verifies email with a correct code', function () {
    $user = unverifiedUserWithCode('654321');

    $response = $this->postJson('/api/email/verify/code', [
        'email'             => 'verifyme@example.com',
        'verification_code' => '654321',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('message', 'Email verified successfully.');

    $user->refresh();
    expect($user->hasVerifiedEmail())->toBeTrue();
    expect($user->verification_code)->toBeNull();
});

it('rejects an incorrect verification code', function () {
    unverifiedUserWithCode('654321');

    $response = $this->postJson('/api/email/verify/code', [
        'email'             => 'verifyme@example.com',
        'verification_code' => '000000',
    ]);

    $response->assertStatus(400)
        ->assertJsonPath('message', 'Invalid or expired verification code.');
});

it('rejects an expired verification code', function () {
    $user = User::factory()->create([
        'email'                    => 'verifyme@example.com',
        'email_verified_at'        => null,
        'verification_code'        => Hash::make('654321'),
        'verification_code_expiry' => now()->subMinute(),
    ]);

    $response = $this->postJson('/api/email/verify/code', [
        'email'             => 'verifyme@example.com',
        'verification_code' => '654321',
    ]);

    $response->assertStatus(400);
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('rejects verification for a nonexistent email', function () {
    $response = $this->postJson('/api/email/verify/code', [
        'email'             => 'nobody@example.com',
        'verification_code' => '123456',
    ]);

    $response->assertStatus(404);
});

it('resends a verification code for an unverified user', function () {
    Queue::fake();
    Mail::fake();

    unverifiedUserWithCode();

    $response = $this->postJson('/api/email/resend-verification', ['email' => 'verifyme@example.com']);

    $response->assertStatus(200)
        ->assertJsonPath('message', 'A new verification code has been sent to your email.');

    Mail::assertQueued(\App\Mail\VerifyEmailMail::class);
});

it('rejects a resend request for an already-verified email', function () {
    User::factory()->create([
        'email'             => 'alreadyverified@example.com',
        'email_verified_at' => now(),
    ]);

    $response = $this->postJson('/api/email/resend-verification', ['email' => 'alreadyverified@example.com']);

    $response->assertStatus(400)
        ->assertJsonPath('message', 'Your email is already verified.');
});

it('returns a generic success for resend on a nonexistent email, to avoid enumeration', function () {
    $response = $this->postJson('/api/email/resend-verification', ['email' => 'nobody@example.com']);

    $response->assertStatus(200)
        ->assertJsonPath('message', 'If that email is registered and unverified, a new code has been sent.');
});

// ── PASSWORD RESET ────────────────────────────────────────────────────────

it('sends a password reset code for a registered email', function () {
    Queue::fake();
    Mail::fake();

    User::factory()->create(['email' => 'reset@example.com']);

    $response = $this->postJson('/api/password/reset/code', ['email' => 'reset@example.com']);

    $response->assertStatus(200)
        ->assertJsonPath('message', 'If that email is registered, a reset code has been sent.');

    Mail::assertQueued(\App\Mail\ResetPasswordEmail::class);
});

it('returns the same generic message for a reset code request on an unregistered email', function () {
    $response = $this->postJson('/api/password/reset/code', ['email' => 'nobody@example.com']);

    $response->assertStatus(200)
        ->assertJsonPath('message', 'If that email is registered, a reset code has been sent.');
});

it('verifies a correct password reset code', function () {
    $user = User::factory()->create([
        'email'                                => 'resetflow@example.com',
        'password_reset_code'                  => Hash::make('111222'),
        'password_reset_code_expires_at'       => now()->addMinutes(30),
        'password_reset_verified'              => false,
    ]);

    $response = $this->postJson('/api/password/reset/verify', [
        'email'      => 'resetflow@example.com',
        'reset_code' => '111222',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('message', 'Reset code verified. You can now reset your password.');

    expect($user->fresh()->password_reset_verified)->toBeTrue();
});

it('rejects an incorrect password reset code', function () {
    User::factory()->create([
        'email'                          => 'resetflow2@example.com',
        'password_reset_code'            => Hash::make('111222'),
        'password_reset_code_expires_at' => now()->addMinutes(30),
    ]);

    $response = $this->postJson('/api/password/reset/verify', [
        'email'      => 'resetflow2@example.com',
        'reset_code' => '999999',
    ]);

    $response->assertStatus(400)
        ->assertJsonPath('message', 'Invalid or expired reset code.');
});

it('completes a password reset after code verification', function () {
    $user = User::factory()->create([
        'email'                          => 'complete@example.com',
        'password'                       => Hash::make('OldPassw0rd!'),
        'password_reset_code'            => Hash::make('111222'),
        'password_reset_code_expires_at' => now()->addMinutes(30),
        'password_reset_verified'        => true,
    ]);

    $response = $this->postJson('/api/password/reset', [
        'email'                 => 'complete@example.com',
        'password'              => 'BrandNewPassw0rd!',
        'password_confirmation' => 'BrandNewPassw0rd!',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('message', 'Password has been reset successfully.');

    $user->refresh();
    expect(Hash::check('BrandNewPassw0rd!', $user->password))->toBeTrue();
    expect($user->password_reset_verified)->toBeFalse();
    expect($user->password_reset_code)->toBeNull();
});

it('rejects a password reset attempt that skipped code verification', function () {
    User::factory()->create([
        'email'                          => 'skipped@example.com',
        'password'                       => Hash::make('OldPassw0rd!'),
        'password_reset_code'            => Hash::make('111222'),
        'password_reset_code_expires_at' => now()->addMinutes(30),
        'password_reset_verified'        => false, // never verified
    ]);

    $response = $this->postJson('/api/password/reset', [
        'email'                 => 'skipped@example.com',
        'password'              => 'BrandNewPassw0rd!',
        'password_confirmation' => 'BrandNewPassw0rd!',
    ]);

    $response->assertStatus(400)
        ->assertJsonPath('message', 'Reset code has expired or was not verified. Please request a new one.');
});

it('rejects a password reset with an expired verified code', function () {
    User::factory()->create([
        'email'                          => 'expiredreset@example.com',
        'password'                       => Hash::make('OldPassw0rd!'),
        'password_reset_code'            => Hash::make('111222'),
        'password_reset_code_expires_at' => now()->subMinute(),
        'password_reset_verified'        => true,
    ]);

    $response = $this->postJson('/api/password/reset', [
        'email'                 => 'expiredreset@example.com',
        'password'              => 'BrandNewPassw0rd!',
        'password_confirmation' => 'BrandNewPassw0rd!',
    ]);

    $response->assertStatus(400);
});

it('rejects a password reset for an unknown email without leaking that fact via status code', function () {
    $response = $this->postJson('/api/password/reset', [
        'email'                 => 'ghost@example.com',
        'password'              => 'BrandNewPassw0rd!',
        'password_confirmation' => 'BrandNewPassw0rd!',
    ]);

    // Same generic 400 as "verified flag not set" — not a 404.
    $response->assertStatus(400)
        ->assertJsonPath('message', 'Invalid request.');
});

it('rejects a weak password on the reset endpoint', function () {
    User::factory()->create([
        'email'                          => 'weakreset@example.com',
        'password_reset_code'            => Hash::make('111222'),
        'password_reset_code_expires_at' => now()->addMinutes(30),
        'password_reset_verified'        => true,
    ]);

    $response = $this->postJson('/api/password/reset', [
        'email'                 => 'weakreset@example.com',
        'password'              => 'alllowercase',
        'password_confirmation' => 'alllowercase',
    ]);

    $response->assertStatus(422);
});
