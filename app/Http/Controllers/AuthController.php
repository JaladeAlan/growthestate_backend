<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesAccountStatus;
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
use App\Jobs\ScreenUserJob;
use Illuminate\Support\Facades\Cookie;

class AuthController extends Controller
{
    use ResolvesAccountStatus;

    // ─────────────────────────────────────────────────────────────────────────
    // AUTH COOKIES
    // ─────────────────────────────────────────────────────────────────────────
    //
    // Frontend contract (see reuvest/frontend utils/tokenStore.ts): the API
    // owns auth_token/user_role as httpOnly cookies set via Set-Cookie on
    // /login and /refresh, cleared on /logout. is_authed is a non-sensitive
    // flag cookie (NOT httpOnly) so client JS can check "is there a
    // session" without ever touching the token itself.
    //
    // `Secure` is only set outside local dev — a Secure cookie is silently
    // dropped by the browser over plain http://localhost, which is exactly
    // how this ends up looking like login "does nothing": the token comes
    // back in the response, but the cookie never gets stored, so every
    // following request looks logged out.

    /**
     * Returns the JWT's expiry as a millisecond epoch timestamp, matching
     * what the frontend expects in `expires_at` for scheduling proactive
     * refresh (it can no longer decode the httpOnly token itself).
     */
    private function tokenExpiresAtMs(): int
    {
        $ttlMinutes = (int) config('jwt.ttl');
        return now()->addMinutes($ttlMinutes)->getTimestamp() * 1000;
    }

    /**
     * Builds the three auth cookies for a successful login/refresh.
     *
     * @return \Symfony\Component\HttpFoundation\Cookie[]
     */
    private function buildAuthCookies(string $token, string $role): array
    {
        $secure   = ! app()->environment('local');
        $minutes  = (int) config('jwt.ttl');
        // Host-only cookie (null) is fine for local dev — localhost:3000 and
        // localhost:8000 share a host, so the port difference doesn't
        // matter for cookie scoping. In production, if the API and frontend
        // live on different subdomains (api.reu.ng vs app.reu.ng), this
        // MUST be set to the shared parent domain (e.g. SESSION_DOMAIN=.reu.ng)
        // or the cookie will only ever be visible to api.reu.ng and the
        // frontend's own middleware will never see it — reusing
        // SESSION_DOMAIN here rather than inventing a new env var since
        // it's already documented in .env.example for exactly this purpose.
        $domain   = config('session.domain');

        return [
            Cookie::make('auth_token', $token, $minutes, '/', $domain, $secure, true, false, 'Lax'),
            Cookie::make('user_role', $role, $minutes, '/', $domain, $secure, true, false, 'Lax'),
            // Deliberately NOT httpOnly — this is the one cookie client JS is allowed to read.
            Cookie::make('is_authed', '1', $minutes, '/', $domain, $secure, false, false, 'Lax'),
        ];
    }

    /**
     * Clears all three auth cookies on logout.
     *
     * @return \Symfony\Component\HttpFoundation\Cookie[]
     */
    private function clearAuthCookies(): array
    {
        return [
            Cookie::forget('auth_token'),
            Cookie::forget('user_role'),
            Cookie::forget('is_authed'),
        ];
    }

    /**
     * Chains multiple withCookie() calls — Laravel's Response only exposes
     * the singular form, not a plural withCookies().
     */
    private function attachCookies($response, array $cookies)
    {
        foreach ($cookies as $cookie) {
            $response = $response->withCookie($cookie);
        }
        return $response;
    }

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
        try {
            $request->validate([
                'name'          => 'required|string|max:255',
                'email'         => 'required|string|email|max:255',
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

        // Email uniqueness is checked separately (not via the 'unique:users'
        // validation rule) so an already-registered email doesn't return a
        // distinguishable response — that would let an attacker enumerate
        // registered accounts. Registration is still bounded by the
        // throttle:5,60 route middleware regardless of outcome.
        if (User::where('email', $request->email)->exists()) {
            Log::info('Registration attempted with existing email', ['email' => $request->email]);

            return $this->sendSuccessResponse(
                null,
                'If this email is not already registered, check your inbox for a verification code.',
                201
            );
        }

        try {
            $user = User::create([
                'name'     => $request->name,
                'email'    => $request->email,
                'password' => Hash::make($request->password),
            ]);
            
            ScreenUserJob::dispatch($user, 'registration')->onQueue('default');

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

        try {
            if (! $token = JWTAuth::attempt($credentials)) {
                RateLimiter::hit($key, 900);
                // Intentionally vague to prevent user enumeration
                return $this->sendErrorResponse('Invalid credentials', 401);
            }
        } catch (JWTException $e) {
            return $this->sendErrorResponse('Could not create token', 500, ['exception' => $e->getMessage()]);
        }

        // Password is correct — now safe to reveal verification status.
        if (! $user->hasVerifiedEmail()) {
            return $this->sendErrorResponse('Please verify your email before logging in.', 403);
        }

        // Clear the limiter on successful login
        RateLimiter::clear($key);

        $role = $user->is_admin ? 'admin' : 'user';

        // Include the same user shape /me returns so the frontend can use
        // this response directly instead of needing a follow-up /me call
        // on every login (see frontend todo doc #2/#28).
        $userPayload = $user->makeHidden([
            'password',
            'transaction_pin',
            'pin_reset_code',
        ]);
        $userPayload->pin_is_set      = $this->userHasPin($user);
        $userPayload->is_kyc_verified = $this->isKycVerified($user);
        $userPayload->kyc_status      = $this->resolveKycStatus($user);

        return $this->attachCookies(
            $this->sendSuccessResponse(
                [
                    'token'      => $token,
                    'expires_at' => $this->tokenExpiresAtMs(),
                    'user'       => $userPayload,
                ],
                'Login successful'
            ),
            $this->buildAuthCookies($token, $role)
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // LOGOUT  POST /api/logout
    // ─────────────────────────────────────────────────────────────────────────

    public function logout()
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());
            return $this->attachCookies(
                $this->sendSuccessResponse([], 'Successfully logged out'),
                $this->clearAuthCookies()
            );
        } catch (JWTException $e) {
            // Even if invalidation fails (e.g. token already expired), still
            // clear the client-visible cookies so the user isn't stuck
            // looking logged in.
            return $this->attachCookies(
                $this->sendErrorResponse('Could not log out', 500, ['exception' => $e->getMessage()]),
                $this->clearAuthCookies()
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // REFRESH  POST /api/refresh
    // ─────────────────────────────────────────────────────────────────────────

    public function refresh()
    {
        try {
            $newToken = JWTAuth::refresh(JWTAuth::getToken());

            // Refresh invalidates the old token and issues a new one, so we
            // need to re-resolve the user against the new token to know
            // their current role for the user_role cookie.
            $user = JWTAuth::setToken($newToken)->toUser();
            $role = $user->is_admin ? 'admin' : 'user';

            return $this->attachCookies(
                $this->sendSuccessResponse(
                    ['token' => $newToken, 'expires_at' => $this->tokenExpiresAtMs()],
                    'Token refreshed successfully'
                ),
                $this->buildAuthCookies($newToken, $role)
            );
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