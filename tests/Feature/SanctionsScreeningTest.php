<?php

use App\Jobs\ScreenUserJob;
use App\Models\SanctionsEntry;
use App\Models\User;
use App\Models\UserScreening;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// ─────────────────────────────────────────────────────────────────────────────
// Helper: seed a sanctions list entry
// ─────────────────────────────────────────────────────────────────────────────

function seedSanctionsEntry(string $fullName, string $source = 'ofac', bool $isPep = false): SanctionsEntry
{
    return SanctionsEntry::create([
        'source'               => $source,
        'source_id'            => md5($fullName . $source),
        'entry_type'           => 'individual',
        'full_name'            => $fullName,
        'full_name_normalized' => SanctionsEntry::normalizeName($fullName),
        'aliases'              => [],
        'aliases_normalized'   => [],
        'is_pep'               => $isPep,
        'program'              => 'SDN',
        'raw'                  => ['source' => $source],
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// ScreenUserJob
// ─────────────────────────────────────────────────────────────────────────────

describe('ScreenUserJob', function () {

    it('sets screening_status to clear when no sanctions match is found', function () {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'name'              => 'Ordinary Person',
            'screening_status'  => 'pending',
        ]);

        // No sanctions entries seeded — clean screen
        ScreenUserJob::dispatchSync($user, 'kyc');

        $this->assertDatabaseHas('users', [
            'id'               => $user->id,
            'screening_status' => 'clear',
        ]);
    });

    it('sets screening_status to blocked when user name exactly matches a sanctions entry', function () {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'name'              => 'Viktor Bout',
            'screening_status'  => 'pending',
        ]);

        seedSanctionsEntry('Viktor Bout');

        ScreenUserJob::dispatchSync($user, 'kyc');

        $this->assertDatabaseHas('users', [
            'id'               => $user->id,
            'screening_status' => 'blocked',
        ]);

        $this->assertDatabaseHas('user_screenings', [
            'user_id' => $user->id,
            'status'  => 'blocked',
            'trigger' => 'kyc',
        ]);
    });

    it('creates a UserScreening record with matches on a hit', function () {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'name'              => 'Roman Abramovich',
            'screening_status'  => 'pending',
        ]);

        seedSanctionsEntry('Roman Abramovich', 'eu');

        ScreenUserJob::dispatchSync($user, 'kyc');

        $screening = UserScreening::where('user_id', $user->id)->first();

        expect($screening)->not->toBeNull();
        expect($screening->matches)->not->toBeEmpty();
        expect($screening->trigger)->toBe('kyc');
    });

    it('matches using normalized name (accent-insensitive, case-insensitive)', function () {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'name'              => 'Soleïmân Raïssi',     // accented version
            'screening_status'  => 'pending',
        ]);

        // Sanctions list stores the ASCII-normalized form
        seedSanctionsEntry('Soleiman Raissi');

        ScreenUserJob::dispatchSync($user, 'kyc');

        $this->assertDatabaseHas('users', [
            'id'               => $user->id,
            'screening_status' => 'blocked',
        ]);
    });

    it('matches on an alias entry', function () {
        $entry = SanctionsEntry::create([
            'source'               => 'un',
            'source_id'            => 'un-alias-001',
            'entry_type'           => 'individual',
            'full_name'            => 'Primary Name',
            'full_name_normalized' => SanctionsEntry::normalizeName('Primary Name'),
            'aliases'              => ['John Smith', 'J. Smith'],
            'aliases_normalized'   => [
                SanctionsEntry::normalizeName('John Smith'),
                SanctionsEntry::normalizeName('J. Smith'),
            ],
            'is_pep'   => false,
            'program'  => 'UN',
            'raw'      => [],
        ]);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'name'              => 'John Smith',
            'screening_status'  => 'pending',
        ]);

        ScreenUserJob::dispatchSync($user, 'registration');

        $this->assertDatabaseHas('users', [
            'id'               => $user->id,
            'screening_status' => 'blocked',
        ]);
    });

    it('does not flag a user with a similar but non-matching name', function () {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'name'              => 'John Smith Junior',   // different from 'John Smith'
            'screening_status'  => 'pending',
        ]);

        seedSanctionsEntry('John Smith');

        ScreenUserJob::dispatchSync($user, 'kyc');

        // If your screening uses exact normalized match, this should be clear.
        // Adjust the assertion if your implementation uses fuzzy matching.
        $this->assertDatabaseHas('users', [
            'id'               => $user->id,
            'screening_status' => 'clear',
        ]);
    });

    it('blocks transacting after being flagged via CheckScreeningClear middleware', function () {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'balance_kobo'      => 5_000_000,
            'screening_status'  => 'flagged',
        ]);

        // Attempt to initiate a deposit — route is protected by screening.transact middleware
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/deposit', [
                'amount'  => 500_000,
                'gateway' => 'paystack',
            ])
            ->assertStatus(403)
            ->assertJsonStructure(['message', 'code']);
    });

    it('blocks a blocked user from transacting', function () {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'balance_kobo'      => 5_000_000,
            'screening_status'  => 'blocked',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/deposit', [
                'amount'  => 500_000,
                'gateway' => 'paystack',
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'SCREENING_BLOCKED');
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Admin compliance endpoints
// ─────────────────────────────────────────────────────────────────────────────

describe('Admin compliance endpoints', function () {

    function complianceAdmin(): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
            'is_admin'          => true,
        ]);
    }

    it('admin can list all flagged screenings', function () {
        $admin = complianceAdmin();

        $flaggedUser = User::factory()->create([
            'email_verified_at' => now(),
            'screening_status'  => 'flagged',
        ]);

        $screening = UserScreening::create([
            'user_id' => $flaggedUser->id,
            'status'  => 'flagged',
            'trigger' => 'kyc',
            'matches' => [['source' => 'ofac', 'full_name' => 'A Bad Actor']],
        ]);

        $this->actingAs($admin, 'api')
            ->getJson('/api/admin/compliance/screenings')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    });

    it('admin can clear a flagged screening', function () {
        $admin = complianceAdmin();

        $flaggedUser = User::factory()->create([
            'email_verified_at' => now(),
            'screening_status'  => 'flagged',
        ]);

        $screening = UserScreening::create([
            'user_id' => $flaggedUser->id,
            'status'  => 'flagged',
            'trigger' => 'kyc',
            'matches' => [],
        ]);

        $this->actingAs($admin, 'api')
            ->postJson("/api/admin/compliance/screenings/{$screening->id}/clear", [
                'notes' => 'Verified by document check — false positive.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('users', [
            'id'               => $flaggedUser->id,
            'screening_status' => 'clear',
        ]);

        $this->assertDatabaseHas('user_screenings', [
            'id'     => $screening->id,
            'status' => 'clear',
        ]);
    });

    it('admin can permanently block a user via a screening decision', function () {
        $admin = complianceAdmin();

        $suspectUser = User::factory()->create([
            'email_verified_at' => now(),
            'screening_status'  => 'flagged',
        ]);

        $screening = UserScreening::create([
            'user_id' => $suspectUser->id,
            'status'  => 'flagged',
            'trigger' => 'kyc',
            'matches' => [['full_name' => 'Viktor Bout']],
        ]);

        $this->actingAs($admin, 'api')
            ->postJson("/api/admin/compliance/screenings/{$screening->id}/block", [
                'notes' => 'Confirmed SDN match.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('users', [
            'id'               => $suspectUser->id,
            'screening_status' => 'blocked',
        ]);

        $this->assertDatabaseHas('user_screenings', [
            'id'     => $screening->id,
            'status' => 'blocked',
        ]);
    });

    it('admin can manually trigger a re-screen for a specific user', function () {
        Queue::fake();

        $admin = complianceAdmin();
        $user  = User::factory()->create([
            'email_verified_at' => now(),
            'screening_status'  => 'clear',
        ]);

        $this->actingAs($admin, 'api')
            ->postJson("/api/admin/compliance/users/{$user->id}/rescreen")
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        Queue::assertPushed(ScreenUserJob::class, fn ($job) =>
            $job->user->id === $user->id && $job->trigger === 'manual'
        );
    });

    it('returns 403 when a non-admin accesses compliance endpoints', function () {
        $regularUser = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($regularUser, 'api')
            ->getJson('/api/admin/compliance/screenings')
            ->assertStatus(403);
    });

    it('returns compliance stats for the admin dashboard', function () {
        $admin = complianceAdmin();

        $this->actingAs($admin, 'api')
            ->getJson('/api/admin/compliance/stats')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => [
                'total_screened',
                'flagged_users',
                'blocked_users',
                'clear_users',
            ]]);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// SanctionsEntry::normalizeName unit-style assertions
// ─────────────────────────────────────────────────────────────────────────────

describe('SanctionsEntry name normalization', function () {

    it('lowercases the name', function () {
        expect(SanctionsEntry::normalizeName('JOHN DOE'))
            ->toBe('john doe');
    });

    it('removes accents', function () {
        expect(SanctionsEntry::normalizeName('José García'))
            ->toBe('jose garcia');
    });

    it('strips punctuation', function () {
        expect(SanctionsEntry::normalizeName("O'Reilly, James"))
            ->toBe('oreilly james');
    });

    it('collapses multiple spaces', function () {
        expect(SanctionsEntry::normalizeName('John    Doe'))
            ->toBe('john doe');
    });

    it('trims leading and trailing whitespace', function () {
        expect(SanctionsEntry::normalizeName('  john doe  '))
            ->toBe('john doe');
    });
});
