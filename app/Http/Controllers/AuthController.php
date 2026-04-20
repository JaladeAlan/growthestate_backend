<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Referral;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Mail;
use App\Services\MailService;
use App\Mail\VerifyEmailMail;
use App\Mail\ResetPasswordEmail;

class AuthController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────────────

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

    /**
     * Shared rate-limiter check for sensitive unauthenticated endpoints.
     * Key: sha1(email|ip) — 3 attempts per 15 minutes.
     * Returns a 429 response on breach, or null if the request is allowed.
     */
    private function checkSensitiveLimit(Request $request, string $email): ?\Illuminate\Http\JsonResponse
    {
        $key = 'sensitive:' . sha1(strtolower(trim($email)) . '|' . $request->ip());

        if (RateLimiter::tooManyAttempts($key, 3)) {
            return response()->json([
                'message'     => 'Too many attempts. Please try again later.',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }

        RateLimiter::hit($key, 900); // 15-minute decay
        return null;
    }

    /**
     * Clear the sensitive rate-limit key on success so legitimate users
     * are not permanently locked for 15 minutes after one successful action.
     */
    private function clearSensitiveLimit(Request $request, string $email): void
    {
        $key = 'sensitive:' . sha1(strtolower(trim($email)) . '|' . $request->ip());
        RateLimiter::clear($key);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // REGISTER  POST /api/register
    // ─────────────────────────────────────────────────────────────────────────

    public function register(Request $request)
    {
        // Rate limit: 5 registrations per hour per IP
        $ipKey = 'register:' . $request->ip();

        if (RateLimiter::tooManyAttempts($ipKey, 5)) {
            return response()->json([
                'message'     => 'Too many registration attempts from this IP. Please try again later.',
                'retry_after' => RateLimiter::availableIn($ipKey),
            ], 429);
        }

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

            // Count this IP hit only after a successful registration
            RateLimiter::hit($ipKey, 3600);

            try {
                MailService::queue(new VerifyEmailMail($user, $verificationCode), $user->email);
            } catch (\Exception $e) {
                Log::error("Failed to queue verification email to user {$user->id}: " . $e->getMessage());
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
                config('app.debug') ? ['exception' => $e->getMessage()] : []
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LOGIN  POST /api/login
    // ─────────────────────────────────────────────────────────────────────────

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

        // Rate limit: 5 failed attempts per 15 minutes per email+IP
        $key = 'login:' . sha1(strtolower(trim($request->email)) . '|' . $request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json([
                'message'     => 'Too many login attempts. Please try again later.',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }

        $credentials = $request->only('email', 'password');
        $user        = User::where('email', $request->email)->first();

        if (! $user) {
            RateLimiter::hit($key, 900);
            // Intentionally vague to prevent user enumeration
            return $this->sendErrorResponse('Invalid credentials', 401);
        }

        if (! $user->hasVerifiedEmail()) {
            return $this->sendErrorResponse('Please verify your email before logging in.', 403);
        }

        try {
            if (! $token = JWTAuth::attempt($credentials)) {
                RateLimiter::hit($key, 900);
                return $this->sendErrorResponse('Invalid credentials', 401);
            }
        } catch (JWTException $e) {
            return $this->sendErrorResponse('Could not create token', 500, ['exception' => $e->getMessage()]);
        }

        // Clear the limiter on successful login
        RateLimiter::clear($key);

        return $this->sendSuccessResponse(['token' => $token], 'Login successful');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LOGOUT  POST /api/logout
    // ─────────────────────────────────────────────────────────────────────────

    public function logout()
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());
            return $this->sendSuccessResponse([], 'Successfully logged out');
        } catch (JWTException $e) {
            return $this->sendErrorResponse('Could not log out', 500, ['exception' => $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // REFRESH  POST /api/refresh
    // ─────────────────────────────────────────────────────────────────────────

    public function refresh()
    {
        try {
            $newToken = JWTAuth::refresh(JWTAuth::getToken());
            return $this->sendSuccessResponse(['token' => $newToken], 'Token refreshed successfully');
        } catch (JWTException $e) {
            return $this->sendErrorResponse('Could not refresh token', 500, ['exception' => $e->getMessage()]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PASSWORD RESET — SEND CODE  POST /api/password/reset/code
    // ─────────────────────────────────────────────────────────────────────────

    public function sendPasswordResetCode(Request $request)
    {
        $request->validate(['email' => 'required|string|email']);

        // Rate limit before doing any DB work
        if ($limited = $this->checkSensitiveLimit($request, $request->email)) {
            return $limited;
        }

        $user = User::where('email', $request->email)->first();

        // Always return the same message to prevent user enumeration
        if (! $user) {
            return $this->sendSuccessResponse([], 'If that email is registered, a reset code has been sent.');
        }

        $resetCode = random_int(100000, 999999);

        $user->password_reset_code            = Hash::make((string) $resetCode);
        $user->password_reset_code_expires_at = now()->addMinutes(30);
        $user->password_reset_verified        = false;
        $user->save();

        try {
            // Queued — no longer blocks the request thread
            MailService::queue(new ResetPasswordEmail($user, $resetCode), $user->email);
        } catch (\Exception $e) {
            Log::error('Failed to queue password reset email', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            return $this->sendErrorResponse(
                'Failed to send password reset email. Please try again.',
                500
            );
        }

        return $this->sendSuccessResponse([], 'If that email is registered, a reset code has been sent.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PASSWORD RESET — VERIFY CODE  POST /api/password/reset/verify
    // ─────────────────────────────────────────────────────────────────────────

    public function verifyPasswordResetCode(Request $request)
    {
        $request->validate([
            'email'      => 'required|string|email',
            'reset_code' => 'required|string|size:6',
        ]);

        if ($limited = $this->checkSensitiveLimit($request, $request->email)) {
            return $limited;
        }

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

        $user->password_reset_verified = true;
        $user->save();

        $this->clearSensitiveLimit($request, $request->email);

        return $this->sendSuccessResponse([], 'Reset code verified. You can now reset your password.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PASSWORD RESET — SET NEW PASSWORD  POST /api/password/reset
    // ─────────────────────────────────────────────────────────────────────────

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
            ], [
                'password.min'       => 'The password must be at least 8 characters long.',
                'password.regex'     => 'The password must include at least one uppercase letter, one lowercase letter, one number, and one special character.',
                'password.confirmed' => 'The password confirmation does not match.',
            ]);
        } catch (ValidationException $e) {
            return $this->sendErrorResponse('Password validation errors occurred', 422, $e->validator->errors());
        }

        if ($limited = $this->checkSensitiveLimit($request, $request->email)) {
            return $limited;
        }

        try {
           DB::transaction(function () use ($request) {
            $user = User::where('email', $request->email)
                ->lockForUpdate()
                ->first();

            if (! $user) {
                abort(400, 'Invalid request.');
            }

            if (
                ! $user->password_reset_verified ||
                ! $user->password_reset_code_expires_at ||
                $user->password_reset_code_expires_at->isPast()
            ) {
                abort(400, 'Reset code has expired or was not verified. Please request a new one.');
            }

            $user->password                       = Hash::make($request->password);
            $user->password_reset_code            = null;
            $user->password_reset_code_expires_at = null;
            $user->password_reset_verified        = false;
            $user->save();
            });
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            if ($e->getCode() >= 400 && $e->getCode() < 500) {
                return $this->sendErrorResponse($e->getMessage(), $e->getCode());
            }
            Log::error('Password reset failed', ['error' => $e->getMessage()]);
            return $this->sendErrorResponse('An unexpected error occurred. Please try again.', 500);
        }

        $this->clearSensitiveLimit($request, $request->email);

        return $this->sendSuccessResponse([], 'Password has been reset successfully.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // EMAIL VERIFICATION  POST /api/email/verify/code
    // ─────────────────────────────────────────────────────────────────────────

    public function verifyEmailCode(Request $request)
    {
        $request->validate([
            'email'             => 'required|string|email',
            'verification_code' => 'required|string|size:6',
        ]);

        if ($limited = $this->checkSensitiveLimit($request, $request->email)) {
            return $limited;
        }

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

        $this->clearSensitiveLimit($request, $request->email);

        return $this->sendSuccessResponse([], 'Email verified successfully.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RESEND VERIFICATION  POST /api/email/resend-verification
    // ─────────────────────────────────────────────────────────────────────────

    public function resendVerification(Request $request)
    {
        $request->validate(['email' => 'required|string|email']);

        // Rate limit first — prevents email flooding
        if ($limited = $this->checkSensitiveLimit($request, $request->email)) {
            return $limited;
        }

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            // Same message as success — prevent enumeration
            return $this->sendSuccessResponse([], 'If that email is registered and unverified, a new code has been sent.');
        }

        if ($user->hasVerifiedEmail()) {
            return $this->sendErrorResponse('Your email is already verified.', 400);
        }

        $verificationCode               = random_int(100000, 999999);
        $user->verification_code        = Hash::make((string) $verificationCode);
        $user->verification_code_expiry = now()->addMinutes(30);
        $user->save();

        try {
            MailService::queue(new VerifyEmailMail($user, $verificationCode), $user->email);
        } catch (\Exception $e) {
            Log::error('Failed to queue verification email', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            return $this->sendErrorResponse(
                'Failed to send verification email. Please try again.',
                500
            );
        }

        return $this->sendSuccessResponse([], 'A new verification code has been sent to your email.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CHANGE PASSWORD  POST /api/user/change-password
    // ─────────────────────────────────────────────────────────────────────────

    public function changePassword(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return $this->sendErrorResponse('Unauthenticated.', 401);
        }

        // Rate limit authenticated users: 5 attempts per 15 minutes
        $key = 'change-password:' . $user->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json([
                'message'     => 'Too many password change attempts. Please try again later.',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
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
                RateLimiter::hit($key, 900);
                return $this->sendErrorResponse('Current password is incorrect.', 400);
            }

            if (Hash::check($request->new_password, $user->password)) {
                return $this->sendErrorResponse('New password cannot be the same as your current password.', 400);
            }

            $user->password = Hash::make($request->new_password);
            $user->save();

            RateLimiter::clear($key);

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