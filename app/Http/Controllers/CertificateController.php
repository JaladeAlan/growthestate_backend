<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Services\CertificateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CertificateController extends Controller
{
    public function __construct(private CertificateService $service) {}

    public function index(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => Certificate::where('user_id', $request->user()->id)
                ->where('status', 'active')
                ->paginate(10)
        ]);
    }

    public function show(Request $request, string $certNumber)
    {
        $cert = Certificate::where('cert_number', $certNumber)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return response()->json(['success' => true, 'data' => $cert]);
    }

    public function download(Request $request, string $certNumber)
    {
        $cert = Certificate::where('cert_number', $certNumber)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if ($cert->status !== 'active') {
            abort(403, 'Certificate revoked');
        }

        Log::info('Download', ['cert' => $certNumber]);

        $bytes = $this->service->renderPdfBytes($cert);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename={$certNumber}.pdf",
        ]);
    }

    public function verify(string $certNumber)
    {
        $cert = Cache::remember("cert_{$certNumber}", 60, fn() =>
            $this->service->verify($certNumber)
        );

        if (!$cert) {
            return response()->json(['success' => false], 404);
        }

        Log::info('Verify', ['cert' => $certNumber]);

        return response()->json([
            'success' => true,
            'data' => [
                'owner' => $this->mask($cert->owner_name),
                'units' => $cert->units,
                'property' => $cert->property_title,
            ]
        ]);
    }

    private function mask($name)
    {
        return substr($name, 0, 1) . '***';
    }

    public function adminIndex()
    {
        return response()->json([
            'success' => true,
            'data' => Certificate::latest()->paginate(25)
        ]);
    }

    public function revoke(Certificate $certificate)
    {
        $certificate->update([
            'status' => 'revoked',
            'revoked_at' => now()
        ]);

        return response()->json(['success' => true]);
    }

    public function regenerate(Certificate $certificate)
    {
        $path = $this->service->regeneratePdf($certificate);

        return response()->json([
            'success' => true,
            'path' => $path
        ]);
    }
}