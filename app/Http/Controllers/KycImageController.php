<?php

namespace App\Http\Controllers;

use App\Models\KycVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;

class KycImageController extends Controller
{
    private const ALLOWED_TYPES = ['id_front', 'id_back', 'selfie'];

    /**
     * Stream a KYC image to the authenticated user who owns it (or an admin).
     * Rate limit: 30 requests per minute per user to prevent bulk enumeration.
     */
    public function show(Request $request, int $id, string $imageType)
    {
        // ── Per-user rate limit ──────────────────────────────────────────────
        $rateLimitKey = 'kyc-image:' . $request->user()->id;

        if (RateLimiter::tooManyAttempts($rateLimitKey, 30)) {
            $retryAfter = RateLimiter::availableIn($rateLimitKey);

            return response()->json([
                'message'     => 'Too many requests. Please slow down.',
                'retry_after' => $retryAfter,
            ], 429);
        }

        RateLimiter::hit($rateLimitKey, 60);

        // ── Validate image type ──────────────────────────────────────────────
        if (! in_array($imageType, self::ALLOWED_TYPES, true)) {
            return response()->json(['message' => 'Invalid image type.'], 400);
        }

        // ── Ownership check ──────────────────────────────────────────────────
        $kyc  = KycVerification::find($id);
        $user = $request->user();

        if (! $kyc) {
            return response()->json(['message' => 'KYC record not found.'], 404);
        }

        if ($kyc->user_id !== $user->id && ! $user->is_admin) {
            // Return 404 instead of 403 to avoid confirming the record exists
            return response()->json(['message' => 'KYC record not found.'], 404);
        }

        // ── Resolve the file path ────────────────────────────────────────────
        $storedPath = $kyc->{$imageType . '_path'} ?? null;

        if (! $storedPath) {
            return response()->json(['message' => 'Image not available.'], 404);
        }

        // Images are stored on the 'local' disk (storage/app/private)
        if (! Storage::disk('local')->exists($storedPath)) {
            return response()->json(['message' => 'Image file not found.'], 404);
        }

        $mimeType = Storage::disk('local')->mimeType($storedPath);
        $stream   = Storage::disk('local')->readStream($storedPath);

        return response()->stream(
            function () use ($stream) {
                fpassthru($stream);
                if (is_resource($stream)) {
                    fclose($stream);
                }
            },
            200,
            [
                'Content-Type'        => $mimeType,
                'Content-Disposition' => 'inline',
                'Cache-Control'       => 'no-store, no-cache, must-revalidate',
                'Pragma'              => 'no-cache',
            ]
        );
    }
}