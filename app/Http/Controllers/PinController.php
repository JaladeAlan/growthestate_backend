<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use App\Services\MailService;

/**
 * Handles transaction PIN lifecycle.
 *
 *   POST /pin/set
 *   POST /pin/update
 *   POST /pin/forgot
 *   POST /pin/verify-code
 *   POST /pin/reset
 *
 * Rate limits:
 *   /pin/forgot, /pin/verify-code, /pin/reset → 3 attempts per 15 min per user+IP
 *   /pin/update                                → 5 attempts per 15 min per user
 */
class PinController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────
    private function pinRateLimitKey(Request $request, string $action): string
    {
        return "pin:{$action}:" . sha1($request->user()->id . '|' . $request->ip());
    }

    /**
     * Check the 3/15-min limit used by the forgot→verify→reset flow.
     * Returns a 429 response on breach, or null if the request is allowed.
     */
    private function checkPinFlowLimit(Request $request, string $action): ?\Illuminate\Http\JsonResponse
    {
        $key = $this->pinRateLimitKey($request, $action);

        if (RateLimiter::tooManyAttempts($key, 3)) {
            return response()->json([
                'success'     => false,
                'message'     => 'Too many attempts. Please wait before trying again.',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }

        RateLimiter::hit($key, 900); // 15-minute window
        return null;
    }

    private function clearPinFlowLimit(Request $request, string $action): void
    {
        RateLimiter::clear($this->pinRateLimitKey($request, $action));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SET  POST /pin/set  — first-time PIN setup
    // ─────────────────────────────────────────────────────────────────────────

    public function set(Request $request)
    {
        $request->validate([
            'pin'              => 'required|digits:4',
            'pin_confirmation' => 'required|same:pin',
        ]);

        $user = $request->user();

        if ($user->transaction_pin) {
            return response()->json([
                'success' => false,
                'message' => 'PIN already set. Use /pin/update to change it.',
            ], 422);
        }

        $user->update(['transaction_pin' => Hash::make($request->pin)]);

        return response()->json(['success' => true, 'message' => 'Transaction PIN set successfully.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // UPDATE  POST /pin/update  — change existing PIN
    // ─────────────────────────────────────────────────────────────────────────

    public function update(Request $request)
    {
        $request->validate([
            'current_pin'      => 'required|digits:4',
            'new_pin'          => 'required|digits:4',
            'pin_confirmation' => 'required|same:new_pin',
        ]);

        $user = $request->user();

        // Rate limit: 5 wrong attempts per 15 minutes per user
        $key = 'pin:update:' . $user->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json([
                'success'     => false,
                'message'     => 'Too many PIN update attempts. Please try again later.',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }

        if (! Hash::check($request->current_pin, $user->transaction_pin)) {
            RateLimiter::hit($key, 900);
            $remaining = max(0, 5 - RateLimiter::attempts($key));

            return response()->json([
                'success'            => false,
                'message'            => 'Current PIN is incorrect.',
                'attempts_remaining' => $remaining,
            ], 422);
        }

        $user->update(['transaction_pin' => Hash::make($request->new_pin)]);

        RateLimiter::clear($key);

        return response()->json(['success' => true, 'message' => 'PIN updated successfully.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FORGOT  POST /pin/forgot  — send reset code to email
    // ─────────────────────────────────────────────────────────────────────────

    public function forgot(Request $request)
    {
        // Rate-limit the entire forgot flow (3 per 15 min per user+IP)
        if ($limited = $this->checkPinFlowLimit($request, 'forgot')) {
            return $limited;
        }

        $user      = $request->user();
        $code      = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = now()->addMinutes(30);

        $user->update([
            'pin_reset_code'       => Hash::make($code),
            'pin_reset_expires_at' => $expiresAt,
        ]);

        MailService::queue(new \App\Mail\TransactionPinResetMail($user, $code), $user->email);
        return response()->json([
            'success' => true,
            'message' => 'A 6-digit reset code has been sent to your email.',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // VERIFY-CODE  POST /pin/verify-code  — validate the code
    // ─────────────────────────────────────────────────────────────────────────

    public function verifyCode(Request $request)
    {
        $request->validate(['code' => 'required|digits:6']);

        if ($limited = $this->checkPinFlowLimit($request, 'verify')) {
            return $limited;
        }

        $user = $request->user();

        if (
            ! $user->pin_reset_code ||
            ! $user->pin_reset_expires_at ||
            now()->isAfter($user->pin_reset_expires_at)
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Code has expired. Please request a new one.',
            ], 422);
        }

        if (! Hash::check($request->code, $user->pin_reset_code)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid code.',
            ], 422);
        }

        // Issue a single-use reset token (stored hashed, expires in 15 min)
        $resetToken = Str::random(40);

        $user->update([
            'pin_reset_token'            => Hash::make($resetToken),
            'pin_reset_token_expires_at' => now()->addMinutes(15),
            'pin_reset_code'             => null,
            'pin_reset_expires_at'       => null,
        ]);

        // Clear the forgot and verify limits on success
        $this->clearPinFlowLimit($request, 'forgot');
        $this->clearPinFlowLimit($request, 'verify');

        return response()->json([
            'success'     => true,
            'reset_token' => $resetToken,
            'message'     => 'Code verified. Use the reset token to set a new PIN within 15 minutes.',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RESET  POST /pin/reset  — set new PIN using the reset token
    // ─────────────────────────────────────────────────────────────────────────

    public function reset(Request $request)
    {
        $request->validate([
            'reset_token'      => 'required|string',
            'new_pin'          => 'required|digits:4',
            'pin_confirmation' => 'required|same:new_pin',
        ]);

        if ($limited = $this->checkPinFlowLimit($request, 'reset')) {
            return $limited;
        }

        $user = $request->user();

        DB::transaction(function () use ($request, $user) {
            $freshUser = \App\Models\User::lockForUpdate()->findOrFail($user->id);

            if (
                ! $freshUser->pin_reset_token ||
                ! $freshUser->pin_reset_token_expires_at ||
                now()->isAfter($freshUser->pin_reset_token_expires_at)
            ) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'reset_token' => 'Reset token has expired. Please start over.',
                ]);
            }

            if (! Hash::check($request->reset_token, $freshUser->pin_reset_token)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'reset_token' => 'Invalid reset token.',
                ]);
            }

            $freshUser->update([
                'transaction_pin'            => Hash::make($request->new_pin),
                'pin_reset_token'            => null,
                'pin_reset_token_expires_at' => null,
            ]);
        });

        // Clear all flow limits on successful reset
        $this->clearPinFlowLimit($request, 'forgot');
        $this->clearPinFlowLimit($request, 'verify');
        $this->clearPinFlowLimit($request, 'reset');

        return response()->json(['success' => true, 'message' => 'Transaction PIN reset successfully.']);
    }
}