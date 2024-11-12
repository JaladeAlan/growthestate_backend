<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Mail;
use App\Mail\VerifyEmailMail; 
use App\Mail\ResetPasswordEmail;

class AuthController extends Controller
{
    // Helper method for standardized success responses
    private function sendSuccessResponse($data, $message = 'Success', $status = 200)
    {
        return response()->json([
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    // Helper method for standardized error responses
    private function sendErrorResponse($message, $status = 400, $errors = [])
    {
        return response()->json([
            'message' => $message,
            'errors' => $errors,
        ], $status);
    }

    // Register a new user
    public function register(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users',
                'password' => [
                    'required', 'string', 'min:8',
                    'regex:/[A-Z]/', 'regex:/[a-z]/',
                    'regex:/[0-9]/', 'regex:/[@$!%*?&#]/',
                    'confirmed'
                ],
            ]);
        } catch (ValidationException $e) {
            return $this->sendErrorResponse('Validation errors occurred', 422, $e->validator->errors());
        }

        try {
            // Create the user and generate a verification code
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);

            $verificationCode = rand(100000, 999999);
            $user->verification_code = $verificationCode;
            $user->save();

            // Send verification email
            Mail::to($user->email)->send(new VerifyEmailMail($user, $verificationCode));
            $token = JWTAuth::fromUser($user);

            return $this->sendSuccessResponse([
                'user' => $user,
                'token' => $token,
            ], 'Registration successful. Please check your email for the verification code.', 201);

        } catch (\Exception $e) {
            return $this->sendErrorResponse('Registration failed. Please try again later.', 500, ['exception' => $e->getMessage()]);
        }
    }

    // Log in an existing user
    public function login(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|string|email|max:255',
                'password' => 'required|string',
            ]);
        } catch (ValidationException $e) {
            return $this->sendErrorResponse('Validation errors occurred', 422, $e->validator->errors());
        }

        $credentials = $request->only('email', 'password');
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return $this->sendErrorResponse('User not found', 404);
        }

        if (!$user->hasVerifiedEmail()) {
            return $this->sendErrorResponse('Please verify your email before logging in.', 403);
        }

        try {
            // Proceed only if the credentials are valid
            if (!$token = JWTAuth::attempt($credentials)) {
                return $this->sendErrorResponse('Invalid credentials', 401);
            }
        } catch (JWTException $e) {
            return $this->sendErrorResponse('Could not create token', 500, ['exception' => $e->getMessage()]);
        }

        return $this->sendSuccessResponse(['token' => $token], 'Login successful');
    }

    // Log out the user
    public function logout()
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());
            return $this->sendSuccessResponse([], 'Successfully logged out');
        } catch (JWTException $e) {
            return $this->sendErrorResponse('Could not log out', 500, ['exception' => $e->getMessage()]);
        }
    }

    // Refresh the token
    public function refresh()
    {
        try {
            $newToken = JWTAuth::refresh(JWTAuth::getToken());
            return $this->sendSuccessResponse(['token' => $newToken], 'Token refreshed successfully');
        } catch (JWTException $e) {
            return $this->sendErrorResponse('Could not refresh token', 500, ['exception' => $e->getMessage()]);
        }
    }

    // Password reset request using a verification code
    public function sendPasswordResetCode(Request $request)
    {
        $request->validate(['email' => 'required|string|email']);

        $user = User::where('email', $request->email)->first();
        if (!$user) {
            return $this->sendErrorResponse('User not found', 404);
        }

        // Generate and save the reset code
        $resetCode = rand(100000, 999999);
        $user->password_reset_code = $resetCode;
        $user->password_reset_code_expires_at = now()->addMinutes(30); // Code expires after 30 minutes
        $user->save();

        try {
            // Send email with reset code
            Mail::to($user->email)->send(new ResetPasswordEmail($user, $resetCode));
        } catch (\Exception $e) {
            // Handle email sending failure
            return $this->sendErrorResponse('Failed to send password reset email. Please try again.', 500, ['exception' => $e->getMessage()]);
        }

        return $this->sendSuccessResponse([], 'Password reset code sent to your email.');
    }

    public function verifyResetCode(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'reset_code' => 'required|string|size:6',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return $this->sendErrorResponse('User not found', 404);
        }

        // Check if the reset code is correct and not expired
        if ($user->password_reset_code !== $request->reset_code || 
            $user->password_reset_code_expires_at->isPast()) {
            return $this->sendErrorResponse('Invalid or expired reset code', 400);
        }

        // Clear the reset code and expiration after successful verification
        $user->password_reset_code = null;
        $user->password_reset_code_expires_at = null;
        $user->save();

        return $this->sendSuccessResponse([], 'Reset code verified. You can now reset your password.', 200);
    }

    public function resetPassword(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|string|email',
                'password' => [
                    'required', 'string', 'min:8', 'confirmed',
                    'regex:/[A-Z]/',   // at least one uppercase letter
                    'regex:/[a-z]/',   // at least one lowercase letter
                    'regex:/[0-9]/',   // at least one digit
                    'regex:/[@$!%*?&#]/',  // at least one special character
                ],
            ], [
                'password.min' => 'The password must be at least 8 characters long.',
                'password.regex' => 'The password must include at least one uppercase letter, one lowercase letter, one number, and one special character.',
                'password.confirmed' => 'The password confirmation does not match.',
            ]);
        } catch (ValidationException $e) {
            return $this->sendErrorResponse('Password validation errors occurred', 422, $e->validator->errors());
        }

        // Find the user by email
        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return $this->sendErrorResponse('No account found with this email. Please check your email address.', 404);
        }

        // Ensure the reset code is already verified and cleared
        if ($user->password_reset_code !== null || $user->password_reset_code_expires_at !== null) {
            return $this->sendErrorResponse('Please verify the reset code before setting a new password.', 400);
        }

        // Update the user's password
        $user->password = Hash::make($request->password);
        $user->save();

        return $this->sendSuccessResponse([], 'Password has been reset successfully.', 200);
    }

    // Verify email using the verification code
    public function verifyEmailCode(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'verification_code' => 'required|string|size:6',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return $this->sendErrorResponse('User not found', 404);
        }

        if ($user->verification_code !== $request->verification_code) {
            return $this->sendErrorResponse('Invalid verification code.', 400);
        }

        $user->markEmailAsVerified();
        $user->verification_code = null;
        $user->save();

        return $this->sendSuccessResponse([], 'Email verified successfully.');
    }

    // Resend email verification code
    public function resendVerificationEmail(Request $request)
    {
        $request->validate(['email' => 'required|string|email']);
    
        $user = User::where('email', $request->email)->first();
    
        if (!$user) {
            return $this->sendErrorResponse('User not found', 404);
        }
    
        if ($user->hasVerifiedEmail()) {
            return $this->sendErrorResponse('Your email is already verified.', 400);
        }
    
        // Generate a new verification code and save it
        $verificationCode = rand(100000, 999999);
        $user->verification_code = $verificationCode;
        $user->save();
    
        try {
            // Send verification email
            Mail::to($user->email)->send(new VerifyEmailMail($user, $verificationCode));
        } catch (\Exception $e) {
            // Handle email sending failure
            return $this->sendErrorResponse('Failed to send verification email. Please try again.', 500, ['exception' => $e->getMessage()]);
        }
    
        return $this->sendSuccessResponse([], 'A new verification code has been sent to your email.');
    }
}    