<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Deposit; // Assuming you have a Deposit model
use App\Notifications\DepositConfirmed;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Illuminate\Support\Facades\URL;

class DepositController extends Controller
{
    // Initialize deposit
    public function initiateDeposit(Request $request)
    {
        try {
            // Check if token exists
            if (!$token = JWTAuth::getToken()) {
                return response()->json(['error' => 'Token not provided'], 401);
            }
            
            // Authenticate the user using JWT token
            $user = JWTAuth::parseToken()->authenticate();
        } catch (TokenExpiredException $e) {
            return response()->json(['error' => 'Token has expired'], 401);
        } catch (TokenInvalidException $e) {
            return response()->json(['error' => 'Token is invalid'], 400);
        } catch (JWTException $e) {
            return response()->json(['error' => 'Token is absent'], 401);
        }

        // Validate the incoming request
        $request->validate([
            'amount' => 'required|numeric|min:1',  // Ensure the amount is a valid positive number
        ]);

        $amount = $request->amount;
        $paystackUrl = 'https://api.paystack.co/transaction/initialize';
        
        // Generate a unique reference for the transaction
        $reference = 'DEPOSIT-' . uniqid();
        
        // Create a signed callback URL with the reference
        $callbackUrl = URL::temporarySignedRoute('deposit.callback', now()->addMinutes(10), ['reference' => $reference]);

        // Send request to Paystack to initiate the transaction
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('PAYSTACK_SECRET_KEY'),
        ])->post($paystackUrl, [
            'email' => $user->email,
            'amount' => $amount * 100,  // Convert amount to kobo
            'callback_url' => $callbackUrl,
            'reference' => $reference,
        ]);

        if ($response->successful()) {
            // Log the deposit initiation
            Log::info("Deposit initiated successfully", [
                'reference' => $reference,
                'user_id' => $user->id,
                'amount' => $amount
            ]);

            // Save deposit details to the database
            Deposit::create([
                'user_id' => $user->id,
                'reference' => $reference,
                'amount' => $amount,
                'status' => 'pending'
            ]);

            return response()->json([
                'payment_url' => $response['data']['authorization_url'],
                'reference' => $reference,
            ]);
        } else {
            Log::error('Failed to initiate deposit', [
                'response' => $response->json(),
                'user_id' => $user->id,
                'amount' => $amount
            ]);
            return response()->json(['error' => 'Failed to initiate deposit', 'details' => $response->json()], 500);
        }
    }

    // Handle deposit callback
    public function handleDepositCallback(Request $request)
    {
        Log::info("Deposit callback accessed", ['method' => $request->method()]);

        // Get the transaction reference from the callback request
        $reference = $request->query('reference');
        $paystackUrl = 'https://api.paystack.co/transaction/verify/' . $reference;

        // Verify the transaction with Paystack
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('PAYSTACK_SECRET_KEY'),
        ])->get($paystackUrl);

        Log::info("Paystack response for deposit verification", ['response' => $response->json(), 'reference' => $reference]);

        // If the payment is successful, process it
        if ($response->successful() && $response['data']['status'] === 'success') {
            $amount = $response['data']['amount'] / 100;  // Convert amount from kobo to Naira
            $userEmail = $response['data']['customer']['email'];

            // Find the user by their email (or other identifiers)
            $user = User::where('email', $userEmail)->first();

            if (!$user) {
                Log::error("User not found for deposit callback", ['reference' => $reference]);
                return response()->json(['error' => 'User not found'], 404);
            }

            // Find the corresponding deposit record using the transaction reference
            $deposit = Deposit::where('reference', $reference)->first();

            if (!$deposit) {
                Log::error("Deposit record not found", ['reference' => $reference]);
                return response()->json(['error' => 'Deposit record not found'], 404);
            }

            try {
                // Use a transaction to update the user's balance and the deposit status
                DB::transaction(function () use ($user, $amount, $deposit) {
                    // Increment the user's balance
                    $user->increment('balance', $amount);

                    // Update the deposit status to "completed" or "success"
                    $deposit->status = 'completed';  // Update status to completed/success
                    $deposit->save();  // Save the deposit record

                    // Notify the user about the successful deposit
                    $user->notify(new DepositConfirmed($amount));

                    Log::info("Deposit successful", ['user_id' => $user->id, 'amount' => $amount, 'deposit_reference' => $deposit->reference]);
                });

                // Return a success message
                return response()->json(['message' => 'Deposit successful', 'amount' => $amount]);

            } catch (\Exception $e) {
                Log::error("Failed to update database on deposit callback", [
                    'error' => $e->getMessage(),
                    'user_id' => $user->id,
                    'amount' => $amount,
                    'deposit_reference' => $deposit->reference,
                ]);
                return response()->json(['error' => 'Failed to update deposit in the database'], 500);
            }
        } else {
            // If the Paystack verification fails, log and return an error
            Log::error("Deposit verification failed", [
                'reference' => $reference,
                'response' => $response->json()
            ]);
            return response()->json(['error' => 'Deposit verification failed'], 400);
        }
    }

}