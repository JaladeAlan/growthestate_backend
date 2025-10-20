<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Deposit;
use App\Notifications\DepositConfirmed;
use App\Notifications\DepositFailedNotification;
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
    public function initiateDeposit(Request $request)
    {
        try {
            if (!$token = JWTAuth::getToken()) {
                return response()->json(['error' => 'Token not provided'], 401);
            }
            
            $user = JWTAuth::parseToken()->authenticate();
        } catch (TokenExpiredException $e) {
            return response()->json(['error' => 'Token has expired'], 401);
        } catch (TokenInvalidException $e) {
            return response()->json(['error' => 'Token is invalid'], 400);
        } catch (JWTException $e) {
            return response()->json(['error' => 'Token is absent'], 401);
        }

        $request->validate([
            'amount' => 'required|numeric|min:1',
        ]);

        $amount = $request->amount;
        $reference = 'DEPOSIT-' . uniqid();
        $callbackUrl = URL::temporarySignedRoute('deposit.callback', now()->addMinutes(10), ['reference' => $reference]);

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('PAYSTACK_SECRET_KEY'),
        ])->post('https://api.paystack.co/transaction/initialize', [
            'email' => $user->email,
            'amount' => $amount * 100,
            'callback_url' => $callbackUrl,
            'reference' => $reference,
        ]);

        if ($response->successful()) {
            Log::info("Deposit initiated successfully", [
                'reference' => $reference,
                'user_id' => $user->id,
                'amount' => $amount
            ]);

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

    public function handleDepositCallback(Request $request)
    {
        Log::info("Deposit callback accessed", ['method' => $request->method()]);

        $reference = $request->query('reference');
        $paystackUrl = 'https://api.paystack.co/transaction/verify/' . $reference;

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('PAYSTACK_SECRET_KEY'),
        ])->get($paystackUrl);

        Log::info("Paystack response for deposit verification", ['response' => $response->json(), 'reference' => $reference]);

        $deposit = Deposit::where('reference', $reference)->first();

        if (!$deposit) {
            Log::error("Deposit record not found", ['reference' => $reference]);
            return response()->json(['error' => 'Deposit record not found'], 404);
        }

        $user = $deposit->user;

        if ($response->successful() && $response['data']['status'] === 'success') {
            $amount = $response['data']['amount'] / 100;

            try {
                DB::transaction(function () use ($user, $amount, $deposit) {
                    $user->increment('balance', $amount);
                    $deposit->status = 'completed';
                    $deposit->save();

                    $user->notify(new DepositConfirmed($amount));

                    Log::info("Deposit successful", [
                        'user_id' => $user->id, 
                        'amount' => $amount, 
                        'deposit_reference' => $deposit->reference
                    ]);
                });

                return response()->json(['message' => 'Deposit successful', 'amount' => $amount]);

            } catch (\Exception $e) {
                Log::error("Database update failed for deposit", [
                    'error' => $e->getMessage(),
                    'user_id' => $user->id,
                    'deposit_reference' => $deposit->reference,
                ]);

                $deposit->status = 'failed';
                $deposit->save();

                $user->notify(new DepositFailedNotification($deposit));

                return response()->json(['error' => 'Failed to update deposit in the database'], 500);
            }
        } else {
            Log::error("Deposit verification failed", [
                'reference' => $reference,
                'response' => $response->json()
            ]);

            $deposit->status = 'failed';
            $deposit->save();

            $user->notify(new DepositFailedNotification($deposit));

            return response()->json(['error' => 'Deposit verification failed'], 400);
        }
    }
}
        