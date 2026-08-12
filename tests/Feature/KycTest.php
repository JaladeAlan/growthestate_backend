<?php

use App\Jobs\ScreenUserJob;
use App\Models\KycVerification;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

// ─────────────────────────────────────────────────────────────────────────────
// KYC submission (user)
// ─────────────────────────────────────────────────────────────────────────────

describe('KYC submission', function () {

    it('submits KYC documents, creates a pending verification, and queues screening', function () {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create(['email_verified_at' => now()]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/kyc/submit', [
                'full_name'     => 'John Doe',
                'date_of_birth' => '1990-05-15',
                'phone_number'  => '08012345678',
                'address'       => '12 Test Avenue, Lagos',
                'city'          => 'Lagos',
                'state'         => 'Lagos',
                'id_type'       => 'nin',
                'id_number'     => '12345678901',
                'id_front'      => UploadedFile::fake()->create('id_front.jpg', 100, 'image/jpeg'),
                'selfie'        => UploadedFile::fake()->image('selfie.jpg', 600, 600),
                'is_pep'        => false,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('kyc_verifications', [
            'user_id'   => $user->id,
            'full_name' => 'John Doe',
            'status'    => 'pending',
        ]);

        Queue::assertPushed(ScreenUserJob::class, fn ($job) =>
            $job->user->id === $user->id && $job->trigger === 'kyc'
        );
    });

    it('rejects a second submission while one is pending', function () {
        Storage::fake('local');

        $user = User::factory()->create(['email_verified_at' => now()]);

        KycVerification::create([
            'user_id'      => $user->id,
            'full_name'    => 'John Doe',
            'date_of_birth' => '1990-05-15',
            'phone_number' => '08012345678',
            'address'      => '12 Test Avenue',
            'city'         => 'Lagos',
            'state'        => 'Lagos',
            'id_type'      => 'nin',
            'id_number'    => '12345678901',
            'selfie_path'  => 'kyc/selfies/test.jpg',
            'status'       => 'pending',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/kyc/submit', [
                'full_name'     => 'John Doe',
                'date_of_birth' => '1990-05-15',
                'phone_number'  => '08012345678',
                'address'       => '12 Test Avenue',
                'city'          => 'Lagos',
                'state'         => 'Lagos',
                'id_type'       => 'nin',
                'id_number'     => '12345678901',
                'id_front'      => UploadedFile::fake()->image('id.jpg'),
                'selfie'        => UploadedFile::fake()->image('selfie.jpg'),
                'is_pep'        => false,
            ])
            ->assertStatus(400);
    });

    it('rejects a submission when KYC is already approved', function () {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        KycVerification::create([
            'user_id'      => $user->id,
            'full_name'    => 'John Doe',
            'date_of_birth' => '1990-05-15',
            'phone_number' => '08012345678',
            'address'      => '12 Test Ave',
            'city'         => 'Lagos',
            'state'        => 'Lagos',
            'id_type'      => 'nin',
            'id_number'    => '12345678901',
            'selfie_path'  => 'kyc/selfies/test.jpg',
            'status'       => 'approved',
            'verified_at'  => now(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/kyc/submit', [
                'full_name'     => 'John Doe',
                'date_of_birth' => '1990-05-15',
                'phone_number'  => '08012345678',
                'address'       => '12 Test Ave',
                'city'          => 'Lagos',
                'state'         => 'Lagos',
                'id_type'       => 'nin',
                'id_number'     => '12345678901',
                'id_front'      => UploadedFile::fake()->image('id.jpg'),
                'selfie'        => UploadedFile::fake()->image('selfie.jpg'),
                'is_pep'        => false,
            ])
            ->assertStatus(400);
    });

    it('returns KYC status not_submitted when no record exists', function () {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/kyc/status')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'not_submitted');
    });

    it('returns the correct KYC status when pending', function () {
        $user = User::factory()->create(['email_verified_at' => now()]);

        KycVerification::create([
            'user_id'      => $user->id,
            'full_name'    => 'Jane Doe',
            'date_of_birth' => '1992-03-10',
            'phone_number' => '09087654321',
            'address'      => '5 Lagos Road',
            'city'         => 'Abuja',
            'state'        => 'FCT',
            'id_type'      => 'passport',
            'id_number'    => 'A12345678',
            'selfie_path'  => 'kyc/selfies/jane.jpg',
            'status'       => 'pending',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/kyc/status')
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.is_verified', false);
    });

    it('flags a PEP self-declaration and creates a UserScreening record', function () {
        Queue::fake();
        Storage::fake('local');

        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/kyc/submit', [
                'full_name'        => 'Victor Politician',
                'date_of_birth'    => '1975-01-01',
                'phone_number'     => '08011111111',
                'address'          => 'Government House, Abuja',
                'city'             => 'Abuja',
                'state'            => 'FCT',
                'id_type'          => 'nin',
                'id_number'        => '98765432100',
                'id_front'         => UploadedFile::fake()->image('id.jpg'),
                'selfie'           => UploadedFile::fake()->image('selfie.jpg'),
                'is_pep'           => true,
                'pep_relationship' => 'self',
                'pep_role'         => 'Senator',
                'pep_country'      => 'NG',
                'pep_details'      => 'Serving senator of the Federal Republic.',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('users', [
            'id'               => $user->id,
            'screening_status' => 'flagged',
        ]);

        $this->assertDatabaseHas('user_screenings', [
            'user_id' => $user->id,
            'trigger' => 'pep_self_declaration',
            'status'  => 'flagged',
        ]);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Admin KYC review
// ─────────────────────────────────────────────────────────────────────────────

describe('Admin KYC review', function () {

    function adminUser(): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
            'is_admin'          => true,
        ]);
    }

    function pendingKyc(int $userId): KycVerification
    {
        return KycVerification::create([
            'user_id'      => $userId,
            'full_name'    => 'Test Applicant',
            'date_of_birth' => '1988-07-22',
            'phone_number' => '08033333333',
            'address'      => '10 Test Road',
            'city'         => 'Port Harcourt',
            'state'        => 'Rivers',
            'id_type'      => 'drivers_license',
            'id_number'    => 'DL123456789',
            'selfie_path'  => 'kyc/selfies/applicant.jpg',
            'id_front_path' => 'kyc/ids/front.jpg',
            'status'       => 'pending',
        ]);
    }

    it('approves a pending KYC submission and marks user as verified', function () {
        Queue::fake();

        $admin    = adminUser();
        $customer = User::factory()->create(['email_verified_at' => now()]);
        $kyc      = pendingKyc($customer->id);

        $response = $this->actingAs($admin, 'api')
            ->postJson("/api/admin/kyc/{$kyc->id}/approve");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('kyc_verifications', [
            'id'          => $kyc->id,
            'status'      => 'approved',
            'verified_by' => $admin->id,
        ]);

        $this->assertDatabaseHas('kyc_verifications', [
            'user_id' => $customer->id,
            'status'  => 'approved',
        ]);
        expect($customer->fresh()->is_kyc_verified)->toBeTrue();

        // Screening dispatched after KYC approval
        Queue::assertPushed(ScreenUserJob::class, fn ($job) =>
            $job->user->id === $customer->id && $job->trigger === 'kyc_approved'
        );
    });

    it('rejects a pending KYC submission with a reason', function () {
        $admin    = adminUser();
        $customer = User::factory()->create(['email_verified_at' => now()]);
        $kyc      = pendingKyc($customer->id);

        $this->actingAs($admin, 'api')
            ->postJson("/api/admin/kyc/{$kyc->id}/reject", [
                'reason' => 'Document image is blurry.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('kyc_verifications', [
            'id'               => $kyc->id,
            'status'           => 'rejected',
            'rejection_reason' => 'Document image is blurry.',
        ]);

        // User not marked as verified
        expect($customer->fresh()->is_kyc_verified)->toBeFalse();
    });

    it('returns 400 when trying to re-approve an already approved KYC', function () {
        $admin    = adminUser();
        $customer = User::factory()->create(['email_verified_at' => now()]);

        $kyc = KycVerification::create([
            'user_id'      => $customer->id,
            'full_name'    => 'Already Approved',
            'date_of_birth' => '1985-01-01',
            'phone_number' => '08044444444',
            'address'      => '1 Test St',
            'city'         => 'Lagos',
            'state'        => 'Lagos',
            'id_type'      => 'nin',
            'id_number'    => '11111111111',
            'selfie_path'  => 'kyc/selfies/approved.jpg',
            'status'       => 'approved',
            'verified_at'  => now(),
        ]);

        $this->actingAs($admin, 'api')
            ->postJson("/api/admin/kyc/{$kyc->id}/approve")
            ->assertStatus(400);
    });

    it('requests resubmission with a reason', function () {
        $admin    = adminUser();
        $customer = User::factory()->create(['email_verified_at' => now()]);
        $kyc      = pendingKyc($customer->id);

        $this->actingAs($admin, 'api')
            ->postJson("/api/admin/kyc/{$kyc->id}/resubmit", [
                'reason' => 'Selfie does not match ID document.',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('kyc_verifications', [
            'id'               => $kyc->id,
            'status'           => 'resubmit',
            'rejection_reason' => 'Selfie does not match ID document.',
        ]);
    });

    it('returns 403 for a non-admin accessing KYC admin endpoints', function () {
        $customer = User::factory()->create(['email_verified_at' => now()]);
        $kyc      = pendingKyc($customer->id);

        $this->actingAs($customer, 'api')
            ->postJson("/api/admin/kyc/{$kyc->id}/approve")
            ->assertStatus(403);
    });
});
