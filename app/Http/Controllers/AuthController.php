<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Referral;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Mail;
use App\Mail\VerifyEmailMail;
use App\Mail\ResetPasswordEmail;

class AuthController extends Controller
{
    private function sendSuccessResponse($data, $message = 'Success', $status = 200)
    {
        return response()->json([
            'message' => $message,
            'data'    => $data,
        ], $status);
    }

    private function sendErrorResponse($message, $status = 400, $errors = [])
    {
        return response()->json([
            'message' => $message,
            'errors'  => $errors,
        ], $status);
    }

    // public function me()
    // {
    //     try {
    //         $user = auth()->user();

    //         if (! $user) {
    //             return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
    //         }

    //         return response()->json([
    //             'success' => true,
    //             'user'    => [
    //                 'id'                     => $user->id,
    //                 'uid'                    => $user->uid,
    //                 'name'                   => $user->name,
    //                 'email'                  => $user->email,
    //                 'email_verified_at'      => $user->email_verified_at,
    //                 'transaction_pin'        => (bool) $user->transaction_pin,
    //                 'is_admin'               => $user->is_admin,
    //                 'created_at'             => $user->created_at,
    //                 'updated_at'             => $user->updated_at,

    //                 // ── Balances ──────────────────────────────────────────
    //                 'balance_kobo'           => $user->balance_kobo,
    //                 'balance_naira'          => $user->balance_kobo / 100,
    //                 'rewards_balance_kobo'   => $user->rewards_balance_kobo,
    //                 'rewards_balance_naira'  => $user->rewards_balance_kobo / 100,
    //                 'total_spendable_kobo'   => $user->total_spendable_kobo,
    //                 'total_spendable_naira'  => $user->total_spendable_kobo / 100,

    //                 // ── Bank ─────────────────────────────────────────────
    //                 'bank_name'              => $user->bank_name,
    //                 'bank_code'              => $user->bank_code,
    //                 'account_number'         => $user->account_number,
    //                 'account_name'           => $user->account_name,

    //                 // ── Referral ─────────────────────────────────────────
    //                 'referral_code'          => $user->referral_code,

    //                 // ── KYC ──────────────────────────────────────────────
    //                 'is_kyc_verified'        => $user->is_kyc_verified,
    //                 'kyc_status'             => $user->kyc_status,
    //             ],
    //         ]);
    //     } catch (\Exception $e) {
    //         Log::error('Error fetching user profile', ['error' => $e->getMessage()]);

    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Could not fetch user',
    //             'error'   => config('app.debug') ? $e->getMessage() : null,
    //         ], 500);
    //     }
    // }

    public function register(Request $request)
    {
        try {
            $request->validate([
                'name'          => 'required|string|max:255',
                'email'         => 'required|string|email|max:255|unique:users',
                'password'      => [
                    'required', 'string', 'min:8',
                    'regex:/[A-Z]/', 'regex:/[a-z]/',
                    'regex:/[0-9]/', 'regex:/[@$!%*?&#]/',
                    'confirmed',
                ],
                'referral_code' => 'nullable|string|exists:users,referral_code',
            ]);
        } catch (ValidationException $e) {
            return $this->sendErrorResponse('Validation errors occurred', 422, $e->validator->errors());
        }

        try {
            $user = User::create([
                'name'     => $request->name,
                'email'    => $request->email,
                'password' => Hash::make($request->password),
            ]);

            if ($request->filled('referral_code')) {
                $referrer = User::where('referral_code', $request->referral_code)->first();

                if ($referrer) {
                    $user->update(['referred_by' => $referrer->id]);

                    Referral::create([
                        'referrer_id'      => $referrer->id,
                        'referred_user_id' => $user->id,
                        'status'           => 'pending',
                    ]);
                }
            }

            $verificationCode               = random_int(100000, 999999);
            $user->verification_code        = Hash::make((string) $verificationCode);
            $user->verification_code_expiry = now()->addMinutes(30);
            $user->save();

            $token = JWTAuth::fromUser($user);

            try {
                Mail::to($user->email)->queue(new VerifyEmailMail($user, $verificationCode));
            } catch (\Exception $e) {
                Log::error("Failed to send verification email to user {$user->id}: " . $e->getMessage());
            }

            return $this->sendSuccessResponse(
                ['user' => $user, 'token' => $token],
                'Registration successful. Please check your email for the verification code.',
                201
            );
        } catch (\Exception $e) {
            Log::error('User registration failed: ' . $e->getMessage());

            return $this->sendErrorResponse(
                'Registration failed. Please try again later.',
                500,
                ['exception' => $e->getMessage()]
            );
        }
    }

    public function login(Request $request)
    {
        try {
            $request->validate([
                'email'    => 'required|string|email|max:255',
                'password' => 'required|string',
            ]);
        } catch (ValidationException $e) {
            return $this->sendErrorResponse('Validation errors occurred', 422, $e->validator->errors());
        }

        $credentials = $request->only('email', 'password');
        $user        = User::where('email', $request->email)->first();

        if (! $user) {
            return $this->sendErrorResponse('User not found', 404);
        }

        if (! $user->hasVerifiedEmail()) {
            return $this->sendErrorResponse('Please verify your email before logging in.', 403);
        }

        try {
            if (! $token = JWTAuth::attempt($credentials)) {
                return $this->sendErrorResponse('Invalid credentials', 401);
            }
        } catch (JWTException $e) {
            return $this->sendErrorResponse('Could not create token', 500, ['exception' => $e->getMessage()]);
        }

        return $this->sendSuccessResponse(['token' => $token], 'Login successful');
    }

    public function logout()
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());
            return $this->sendSuccessResponse([], 'Successfully logged out');
        } catch (JWTException $e) {
            return $this->sendErrorResponse('Could not log out', 500, ['exception' => $e->getMessage()]);
        }
    }

    public function refresh()
    {
        try {
            $newToken = JWTAuth::refresh(JWTAuth::getToken());
            return $this->sendSuccessResponse(['token' => $newToken], 'Token refreshed successfully');
        } catch (JWTException $e) {
            return $this->sendErrorResponse('Could not refresh token', 500, ['exception' => $e->getMessage()]);
        }
    }

    public function sendPasswordResetCode(Request $request)
    {
        $request->validate(['email' => 'required|string|email']);

        $user = User::where('email', $request->email)->first();
        if (! $user) {
            return $this->sendSuccessResponse([], 'If that email is registered, a reset code has been sent.');
        }

        $resetCode = random_int(100000, 999999);

        $user->password_reset_code            = Hash::make((string) $resetCode);
        $user->password_reset_code_expires_at = now()->addMinutes(30);
        $user->password_reset_verified        = false;
        $user->save();

        try {
            Mail::to($user->email)->send(new ResetPasswordEmail($user, $resetCode));
        } catch (\Exception $e) {
            return $this->sendErrorResponse(
                'Failed to send password reset email. Please try again.',
                500,
                ['exception' => $e->getMessage()]
            );
        }

        return $this->sendSuccessResponse([], 'If that email is registered, a reset code has been sent.');
    }

    /**
     * Verify reset code — for UX only (shows user the code is valid).
     */
    public function verifyResetCode(Request $request)
    {
        $request->validate([
            'email'      => 'required|string|email',
            'reset_code' => 'required|string|size:6',
        ]);

        $user = User::where('email', $request->email)->first();

        if (
            ! $user ||
            ! $user->password_reset_code ||
            ! $user->password_reset_code_expires_at ||
            $user->password_reset_code_expires_at->isPast() ||
            ! Hash::check((string) $request->reset_code, $user->password_reset_code)
        ) {
            return $this->sendErrorResponse('Invalid or expired reset code.', 400);
        }

        // Mark as verified so the UI can proceed to the new-password step.
        // resetPassword() will re-validate independently.
        $user->password_reset_verified = true;
        $user->save();

        return $this->sendSuccessResponse([], 'Reset code verified. You can now reset your password.');
    }

    /**
     * Reset password — atomically re-validates the code and expiry inside a
     * locked transaction to prevent TOCTOU race conditions.
     */
    public function resetPassword(Request $request)
    {
        try {
            $request->validate([
                'email'    => 'required|string|email',
                'password' => [
                    'required', 'string', 'min:8', 'confirmed',
                    'regex:/[A-Z]/', 'regex:/[a-z]/',
                    'regex:/[0-9]/', 'regex:/[@$!%*?&#]/',
                ],
                'reset_code' => 'required|string|size:6',
            ], [
                'password.min'       => 'The password must be at least 8 characters long.',
                'password.regex'     => 'The password must include at least one uppercase letter, one lowercase letter, one number, and one special character.',
                'password.confirmed' => 'The password confirmation does not match.',
            ]);
        } catch (ValidationException $e) {
            return $this->sendErrorResponse('Password validation errors occurred', 422, $e->validator->errors());
        }

        try {
            DB::transaction(function () use ($request) {
                // Lock the user row — concurrent resets queue behind this one
                $user = User::where('email', $request->email)
                    ->lockForUpdate()
                    ->first();

                if (! $user) {
                    abort(400, 'Invalid request.');
                }

                // Expiry is the primary guard — checked first
                if (
                    ! $user->password_reset_code ||
                    ! $user->password_reset_code_expires_at ||
                    $user->password_reset_code_expires_at->isPast()
                ) {
                    abort(400, 'Reset code has expired. Please request a new one.');
                }

                // Code integrity check
                if (! Hash::check((string) $request->reset_code, $user->password_reset_code)) {
                    abort(400, 'Invalid reset code.');
                }

                $user->password                       = Hash::make($request->password);
                $user->password_reset_code            = null;
                $user->password_reset_code_expires_at = null;
                $user->password_reset_verified        = false;
                $user->save();
            });
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e; // Let abort() responses pass through
        } catch (\Exception $e) {
            // Re-surface abort() messages as proper error responses
            if ($e->getCode() >= 400 && $e->getCode() < 500) {
                return $this->sendErrorResponse($e->getMessage(), $e->getCode());
            }
            Log::error('Password reset failed', ['error' => $e->getMessage()]);
            return $this->sendErrorResponse('An unexpected error occurred. Please try again.', 500);
        }

        return $this->sendSuccessResponse([], 'Password has been reset successfully.');
    }

    public function verifyEmailCode(Request $request)
    {
        $request->validate([
            'email'             => 'required|string|email',
            'verification_code' => 'required|string|size:6',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            return $this->sendErrorResponse('User not found', 404);
        }

        if (
            ! $user->verification_code ||
            ! $user->verification_code_expiry ||
            now()->isAfter($user->verification_code_expiry) ||
            ! Hash::check((string) $request->verification_code, $user->verification_code)
        ) {
            return $this->sendErrorResponse('Invalid or expired verification code.', 400);
        }

        $user->markEmailAsVerified();
        $user->verification_code        = null;
        $user->verification_code_expiry = null;
        $user->save();

        return $this->sendSuccessResponse([], 'Email verified successfully.');
    }

    public function resendVerificationEmail(Request $request)
    {
        $request->validate(['email' => 'required|string|email']);

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            return $this->sendErrorResponse('User not found', 404);
        }

        if ($user->hasVerifiedEmail()) {
            return $this->sendErrorResponse('Your email is already verified.', 400);
        }

        $verificationCode               = random_int(100000, 999999);
        $user->verification_code        = Hash::make((string) $verificationCode);
        $user->verification_code_expiry = now()->addMinutes(30);
        $user->save();

        try {
            Mail::to($user->email)->send(new VerifyEmailMail($user, $verificationCode));
        } catch (\Exception $e) {
            return $this->sendErrorResponse(
                'Failed to send verification email. Please try again.',
                500,
                ['exception' => $e->getMessage()]
            );
        }

        return $this->sendSuccessResponse([], 'A new verification code has been sent to your email.');
    }

    public function changePassword(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return $this->sendErrorResponse('Unauthenticated.', 401);
        }

        try {
            $request->validate([
                'current_password' => 'required|string',
                'new_password'     => [
                    'required', 'string', 'min:8', 'confirmed',
                    'regex:/[A-Z]/', 'regex:/[a-z]/',
                    'regex:/[0-9]/', 'regex:/[@$!%*?&#]/',
                ],
            ], [
                'new_password.min'       => 'The new password must be at least 8 characters long.',
                'new_password.regex'     => 'The new password must include at least one uppercase letter, one lowercase letter, one number, and one special character.',
                'new_password.confirmed' => 'The new password confirmation does not match.',
            ]);
        } catch (ValidationException $e) {
            return $this->sendErrorResponse('Password validation errors occurred', 422, $e->validator->errors());
        }

        try {
            if (! Hash::check($request->current_password, $user->password)) {
                return $this->sendErrorResponse('Current password is incorrect.', 400);
            }

            if (Hash::check($request->new_password, $user->password)) {
                return $this->sendErrorResponse('New password cannot be the same as your current password.', 400);
            }

            $user->password = Hash::make($request->new_password);
            $user->save();

            return $this->sendSuccessResponse([], 'Password has been changed successfully.');
        } catch (\Exception $e) {
            Log::error('Error while changing password', [
                'user_id'   => $user->id ?? null,
                'exception' => $e->getMessage(),
            ]);

            return $this->sendErrorResponse('An unexpected error occurred while changing the password.', 500);
        }
    }
}