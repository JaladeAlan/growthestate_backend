<?php

use App\Models\Certificate;
use App\Models\Purchase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// ─────────────────────────────────────────────────────────────────────────────
// Helpers — prefixed with cert_ to avoid global function name collisions
// ─────────────────────────────────────────────────────────────────────────────

function cert_make_user(array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'email_verified_at' => now(),
        'is_suspended'      => false,
    ], $attrs));
}

function cert_make_land(): int
{
    return DB::table('lands')->insertGetId([
        'title'           => 'Certificate Plot ' . Str::random(4),
        'location'        => 'Ogun State, Nigeria',
        'size'            => 2000.0,
        'total_units'     => 2000,
        'available_units' => 1000,
        'is_available'    => true,
        'description'     => 'Land for certificate tests.',
        'plot_identifier' => 'PLT-' . Str::random(8),
        'lga'             => 'Abeokuta North',
        'state'           => 'Ogun',
        'tenure'          => 'leasehold',
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);
}

function cert_make_purchase(int $userId, int $landId, int $units = 5): Purchase
{
    return Purchase::create([
        'user_id'                    => $userId,
        'land_id'                    => $landId,
        'units'                      => $units,
        'total_amount_paid_kobo'     => $units * 500_000,
        'total_amount_received_kobo' => 0,
        'status'                     => 'active',
        'reference'                  => 'PUR-CERT-' . Str::random(10),
        'purchase_date'              => now(),
        'created_at'                 => now(),
        'updated_at'                 => now(),
    ]);
}

function cert_make_active(int $userId, int $landId, int $purchaseId): Certificate
{
    $certNumber = 'CERT-' . strtoupper(Str::random(8));
    $reference  = 'PUR-CERT-TEST';
    $ownerName  = 'Test Owner';
    $signature  = strtoupper(hash_hmac(
        'sha256',
        "{$certNumber}|{$reference}|{$ownerName}",
        config('services.certificate.secret')
    ));

    return Certificate::create([
        'user_id'            => $userId,
        'land_id'            => $landId,
        'purchase_id'        => $purchaseId,
        'cert_number'        => $certNumber,
        'digital_signature'  => $signature,
        'sequence_number'    => DB::table('certificates')->count() + 1,
        'owner_name'         => $ownerName,
        'units'              => 5,
        'total_invested'     => 2500.00,
        'purchase_reference' => $reference,
        'property_title'     => 'Certificate Plot',
        'property_location'  => 'Ogun State, Nigeria',
        'plot_identifier'    => 'PLT-CERT-001',
        'tenure'             => 'leasehold',
        'lga'                => 'Abeokuta North',
        'state'              => 'Ogun',
        'status'             => 'active',
        'issued_at'          => now(),
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Certificate listing and retrieval (authenticated user)
// ─────────────────────────────────────────────────────────────────────────────

describe('Certificate listing', function () {

    it('returns all certificates for the authenticated user', function () {
        $user     = cert_make_user();
        $landId   = cert_make_land();
        $purchase = cert_make_purchase($user->id, $landId);
        $cert     = cert_make_active($user->id, $landId, $purchase->id);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/certificates');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');

        expect(collect($response->json('data'))->pluck('cert_number')->contains($cert->cert_number))
            ->toBeTrue();
    });

    it('does not return certificates belonging to another user', function () {
        $owner    = cert_make_user();
        $other    = cert_make_user();
        $landId   = cert_make_land();
        $purchase = cert_make_purchase($owner->id, $landId);

        cert_make_active($owner->id, $landId, $purchase->id);

        $this->actingAs($other, 'sanctum')
            ->getJson('/api/certificates')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    });

    it('returns 200 with an empty array when the user has no certificates', function () {
        $user = cert_make_user();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/certificates')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    });

    it('retrieves a single certificate by cert_number for the owner', function () {
        $user     = cert_make_user();
        $landId   = cert_make_land();
        $purchase = cert_make_purchase($user->id, $landId);
        $cert     = cert_make_active($user->id, $landId, $purchase->id);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/certificates/{$cert->cert_number}")
            ->assertStatus(200)
            ->assertJsonPath('data.cert_number', $cert->cert_number)
            ->assertJsonPath('data.status', 'active');
    });

    it("returns 404 when retrieving another user's certificate by cert_number", function () {
        $owner    = cert_make_user();
        $other    = cert_make_user();
        $landId   = cert_make_land();
        $purchase = cert_make_purchase($owner->id, $landId);
        $cert     = cert_make_active($owner->id, $landId, $purchase->id);

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/certificates/{$cert->cert_number}")
            ->assertStatus(404);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Public certificate verification
// ─────────────────────────────────────────────────────────────────────────────

describe('Public certificate verification', function () {

    it('verifies an active certificate by cert_number (public endpoint)', function () {
        $user     = cert_make_user();
        $landId   = cert_make_land();
        $purchase = cert_make_purchase($user->id, $landId);
        $cert     = cert_make_active($user->id, $landId, $purchase->id);

        $this->getJson("/api/verify/{$cert->cert_number}")
            ->assertStatus(200)
            ->assertJsonPath('valid', true)
            ->assertJsonPath('cert_number', $cert->cert_number)
            ->assertJsonPath('status', 'active')
            ->assertJsonStructure([
                'valid',
                'cert_number',
                'owner_name',
                'property_title',
                'units',
                'issued_at',
                'status',
            ]);
    });

    it('returns valid: false for a revoked certificate', function () {
        $user     = cert_make_user();
        $landId   = cert_make_land();
        $purchase = cert_make_purchase($user->id, $landId);
        $cert     = cert_make_active($user->id, $landId, $purchase->id);

        $cert->update(['status' => 'revoked', 'revoked_at' => now()]);

        $this->getJson("/api/verify/{$cert->cert_number}")
            ->assertStatus(200)
            ->assertJsonPath('valid', false)
            ->assertJsonPath('status', 'revoked');
    });

    it('returns 404 for a cert_number that does not exist', function () {
        $this->getJson('/api/verify/CERT-DOESNOTEXIST')
            ->assertStatus(404);
    });

    it('does not expose sensitive owner data in the public verification response', function () {
        $user     = cert_make_user();
        $landId   = cert_make_land();
        $purchase = cert_make_purchase($user->id, $landId);
        $cert     = cert_make_active($user->id, $landId, $purchase->id);

        $response = $this->getJson("/api/verify/{$cert->cert_number}");
        $response->assertStatus(200);

        $data = $response->json();
        expect(array_key_exists('digital_signature', $data))->toBeFalse();
        expect(array_key_exists('user_id', $data))->toBeFalse();
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Certificate download (authenticated)
// ─────────────────────────────────────────────────────────────────────────────

describe('Certificate download', function () {

    it('returns a downloadable PDF for the owning user', function () {
        Storage::fake('local');

        $user     = cert_make_user();
        $landId   = cert_make_land();
        $purchase = cert_make_purchase($user->id, $landId);
        $cert     = cert_make_active($user->id, $landId, $purchase->id);

        Storage::put("certificates/{$cert->cert_number}.pdf", '%PDF-1.4 fake pdf content');
        $cert->update(['pdf_path' => "certificates/{$cert->cert_number}.pdf"]);

        $response = $this->actingAs($user, 'sanctum')
            ->get("/api/certificates/{$cert->cert_number}/download");

        expect(in_array($response->status(), [200, 302]))->toBeTrue();
    });

    it('returns 404 when a different user tries to download the certificate', function () {
        Storage::fake('local');

        $owner    = cert_make_user();
        $other    = cert_make_user();
        $landId   = cert_make_land();
        $purchase = cert_make_purchase($owner->id, $landId);
        $cert     = cert_make_active($owner->id, $landId, $purchase->id);

        Storage::put("certificates/{$cert->cert_number}.pdf", '%PDF-1.4 fake content');
        $cert->update(['pdf_path' => "certificates/{$cert->cert_number}.pdf"]);

        $this->actingAs($other, 'sanctum')
            ->get("/api/certificates/{$cert->cert_number}/download")
            ->assertStatus(404);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Admin certificate management
// ─────────────────────────────────────────────────────────────────────────────

describe('Admin certificate management', function () {

    it('admin can list all certificates', function () {
        $admin    = User::factory()->create(['email_verified_at' => now(), 'is_admin' => true]);
        $user     = cert_make_user();
        $landId   = cert_make_land();
        $purchase = cert_make_purchase($user->id, $landId);
        cert_make_active($user->id, $landId, $purchase->id);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/certificates')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    });

    it('admin can revoke an active certificate', function () {
        $admin    = User::factory()->create(['email_verified_at' => now(), 'is_admin' => true]);
        $user     = cert_make_user();
        $landId   = cert_make_land();
        $purchase = cert_make_purchase($user->id, $landId);
        $cert     = cert_make_active($user->id, $landId, $purchase->id);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/certificates/{$cert->id}/revoke")
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('certificates', [
            'id'     => $cert->id,
            'status' => 'revoked',
        ]);
    });

    it('revoked certificate fails public verification after revocation', function () {
        $admin    = User::factory()->create(['email_verified_at' => now(), 'is_admin' => true]);
        $user     = cert_make_user();
        $landId   = cert_make_land();
        $purchase = cert_make_purchase($user->id, $landId);
        $cert     = cert_make_active($user->id, $landId, $purchase->id);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/admin/certificates/{$cert->id}/revoke");

        $this->getJson("/api/verify/{$cert->cert_number}")
            ->assertStatus(200)
            ->assertJsonPath('valid', false);
    });

    it('admin can regenerate a certificate (re-issue as new PDF)', function () {
        Queue::fake();

        $admin    = User::factory()->create(['email_verified_at' => now(), 'is_admin' => true]);
        $user     = cert_make_user();
        $landId   = cert_make_land();
        $purchase = cert_make_purchase($user->id, $landId);
        $cert     = cert_make_active($user->id, $landId, $purchase->id);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/admin/certificates/{$cert->id}/regenerate")
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    });

    it('returns 403 when a non-admin accesses the revoke endpoint', function () {
        $user     = cert_make_user();
        $landId   = cert_make_land();
        $purchase = cert_make_purchase($user->id, $landId);
        $cert     = cert_make_active($user->id, $landId, $purchase->id);

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/admin/certificates/{$cert->id}/revoke")
            ->assertStatus(403);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Digital signature integrity
// ─────────────────────────────────────────────────────────────────────────────

describe('Certificate digital signature integrity', function () {

    it('every issued certificate has a non-empty digital_signature', function () {
        $user     = cert_make_user();
        $landId   = cert_make_land();
        $purchase = cert_make_purchase($user->id, $landId);
        $cert     = cert_make_active($user->id, $landId, $purchase->id);

        expect($cert->digital_signature)->not->toBeEmpty();
        expect(strlen($cert->digital_signature))->toBeGreaterThan(32);
    });

    it('digital_signature is unique across different certificates', function () {
        $user  = cert_make_user();

        $land1 = cert_make_land();
        $land2 = cert_make_land();

        $purchase1 = cert_make_purchase($user->id, $land1, 3);
        $purchase2 = cert_make_purchase($user->id, $land2, 7);

        $cert1 = cert_make_active($user->id, $land1, $purchase1->id);
        $cert2 = cert_make_active($user->id, $land2, $purchase2->id);

        expect($cert1->digital_signature)->not->toBe($cert2->digital_signature);
        expect($cert1->cert_number)->not->toBe($cert2->cert_number);
    });
});