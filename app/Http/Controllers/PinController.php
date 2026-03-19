<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Handles transaction PIN lifecycle.
 * Split from the monolithic UserController.
 *
 * Routes:
 *   POST /pin/set
 *   POST /pin/update
 *   POST /pin/forgot
 *   POST /pin/verify-code
 *   POST /pin/reset
 */
class PinController extends Controller
{
    // POST /pin/set  — first-time PIN setup
    public function set(Request $request)
    {
        $request->validate([
            'pin'             => 'required|digits:4',
            'pin_confirmation'=> 'required|same:pin',
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

    // POST /pin/update  — change existing PIN
    public function update(Request $request)
    {
        $request->validate([
            'current_pin'     => 'required|digits:4',
            'new_pin'         => 'required|digits:4',
            'pin_confirmation'=> 'required|same:new_pin',
        ]);

        $user = $request->user();

        if (! Hash::check($request->current_pin, $user->transaction_pin)) {
            return response()->json(['success' => false, 'message' => 'Current PIN is incorrect.'], 422);
        }

        $user->update(['transaction_pin' => Hash::make($request->new_pin)]);

        return response()->json(['success' => true, 'message' => 'PIN updated successfully.']);
    }

    // POST /pin/forgot  — send reset code to email
    public function forgot(Request $request)
    {
        $user = $request->user();

        $code      = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = now()->addMinutes(30);

        $user->update([
            'pin_reset_code'            => Hash::make($code),
            'pin_reset_expires_at' => $expiresAt,
        ]);

        Mail::to($user->email)->queue(new \App\Mail\TransactionPinResetMail($user, $code));

        return response()->json([
            'success' => true,
            'message' => 'A 6-digit reset code has been sent to your email.',
        ]);
    }

    // POST /pin/verify-code  — validate the code (returns a short-lived token)
    public function verifyCode(Request $request)
    {
        $request->validate(['code' => 'required|digits:6']);

        $user = $request->user();

        if (
            ! $user->pin_reset_code ||
            ! $user->pin_reset_expires_at ||
            now()->isAfter($user->pin_reset_expires_at)
        ) {
            return response()->json(['success' => false, 'message' => 'Code has expired. Please request a new one.'], 422);
        }

        if (! Hash::check($request->code, $user->pin_reset_code)) {
            return response()->json(['success' => false, 'message' => 'Invalid code.'], 422);
        }

        // Issue a single-use reset token (stored hashed, expires in 15 min)
        $resetToken = Str::random(40);

        $user->update([
            'pin_reset_token'            => Hash::make($resetToken),
            'pin_reset_token_expires_at' => now()->addMinutes(15),
            'pin_reset_code'             => null,
            'pin_reset_expires_at'  => null,
        ]);

        return response()->json([
            'success'      => true,
            'reset_token'  => $resetToken,
            'message'      => 'Code verified. Use the reset token to set a new PIN within 15 minutes.',
        ]);
    }

    // POST /pin/reset  — set new PIN using the reset token
    public function reset(Request $request)
    {
        $request->validate([
            'reset_token'     => 'required|string',
            'new_pin'         => 'required|digits:4',
            'pin_confirmation'=> 'required|same:new_pin',
        ]);

        $user = $request->user();

        DB::transaction(function () use ($request, $user) {
            // Lock the row to prevent TOCTOU race conditions
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

        return response()->json(['success' => true, 'message' => 'Transaction PIN reset successfully.']);
    }
}