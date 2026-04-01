<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Services\CertificateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CertificateController extends Controller
{
    public function __construct(private CertificateService $service) {}

    // ─────────────────────────────────────────────────────────────────────────
    // GET /certificates   (authenticated — owner's own certs)
    // ─────────────────────────────────────────────────────────────────────────
    public function index(Request $request)
    {
        return response()->json([
            'success' => true,
            'data'    => Certificate::where('user_id', $request->user()->id)
                ->where('status', 'active')
                ->paginate(10),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /certificates/{certNumber}   (authenticated)
    // ─────────────────────────────────────────────────────────────────────────
    public function show(Request $request, string $certNumber)
    {
        $cert = Certificate::where('cert_number', $certNumber)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return response()->json(['success' => true, 'data' => $cert]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /certificates/{certNumber}/download   (authenticated, active only)
    // ─────────────────────────────────────────────────────────────────────────
    public function download(Request $request, string $certNumber)
    {
        $cert = Certificate::where('cert_number', $certNumber)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if ($cert->status !== 'active') {
            abort(403, 'Certificate is revoked and cannot be downloaded.');
        }

        Log::info('Certificate downloaded', [
            'cert_number' => $certNumber,
            'user_id'     => $request->user()->id,
        ]);

        $bytes = $this->service->renderPdfBytes($cert);

        return response($bytes, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$certNumber}.pdf\"",
            'Content-Length'      => strlen($bytes),
            'Cache-Control'       => 'no-store',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /verify/{certNumber}   (PUBLIC — no auth required)
    //
    // Returns enough data for the public verify page (ResultCard) while
    // keeping sensitive info out.
    // ─────────────────────────────────────────────────────────────────────────
    public function verify(string $certNumber)
    {
        // Cache for 60 s to avoid hammering the DB on QR-scan bursts.
        // We cache the cert model; null means "not found / invalid".
        $cert = Cache::remember("cert_verify_{$certNumber}", 60, function () use ($certNumber) {
            return $this->service->verify($certNumber);
        });

        if (! $cert) {
            Log::warning('Certificate verification failed', [
                'cert_number' => $certNumber,
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Certificate not found or signature invalid.',
            ], 404);
        }

        Log::info('Certificate verified publicly', ['cert_number' => $certNumber]);

        return response()->json([
            'success' => true,
            'data'    => [
                'cert_number'       => $cert->cert_number,
                'owner_name'        => $cert->owner_name,
                'units'             => $cert->units,
                'property_title'    => $cert->property_title,
                'property_location' => $cert->property_location,
                'total_invested'    => $cert->total_invested,
                'issued_at'         => $cert->issued_at,
                'last_updated_at'   => $cert->last_updated_at ?? null,
                'status'            => $cert->status,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ADMIN routes
    // ─────────────────────────────────────────────────────────────────────────

    public function adminIndex()
    {
        return response()->json([
            'success' => true,
            'data'    => Certificate::with(['user:id,name,email', 'land:id,title'])
                ->latest()
                ->paginate(25),
        ]);
    }

    public function revoke(Certificate $certificate)
    {
        $certificate->update([
            'status'     => 'revoked',
            'revoked_at' => now(),
        ]);

        // Bust the public verify cache so the next QR scan reflects immediately
        Cache::forget("cert_verify_{$certificate->cert_number}");

        Log::info('Certificate revoked by admin', ['cert_id' => $certificate->id]);

        return response()->json(['success' => true]);
    }

    public function regenerate(Certificate $certificate)
    {
        $path = $this->service->regeneratePdf($certificate);

        Cache::forget("cert_verify_{$certificate->cert_number}");

        return response()->json(['success' => true, 'path' => $path]);
    }
}