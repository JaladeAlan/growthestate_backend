<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Withdrawal;
use App\Notifications\WithdrawalConfirmed;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

class WithdrawalController extends Controller
{
    // Method to request a withdrawal
    public function requestWithdrawal(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();
        $request->validate(['amount' => 'required|numeric|min:1']);

        if ($user->balance < $request->amount) {
            return response()->json(['error' => 'Insufficient balance'], 400);
        }

        try {
            // Generate a unique reference code for the withdrawal
            $referenceCode = 'WD-' . now()->format('Ymd-His') . '-' . Str::random(6);

            DB::transaction(function () use ($user, $request, $referenceCode) {
                // Deduct the balance from the user's account
                $user->decrement('balance', $request->amount);

                // Create a withdrawal record
                $withdrawal = Withdrawal::create([
                    'user_id' => $user->id,
                    'amount' => $request->amount,
                    'status' => 'pending',
                    'reference' => $referenceCode,
                ]);

                // Initiate the transfer to the user's bank using Paystack
                $this->initiatePaystackTransfer($user, $request->amount, $referenceCode);
            });

            return response()->json(['message' => 'Withdrawal request initiated', 'reference' => $referenceCode]);
        } catch (\Exception $e) {
            Log::error('Withdrawal request failed', ['error' => $e->getMessage(), 'referenceCode' => $referenceCode]);
            return response()->json(['error' => 'Withdrawal request failed'], 500);
        }
    }

    // Initiate the transfer with Paystack
    protected function initiatePaystackTransfer($user, $amount, $referenceCode)
    {
        // Create a recipient if needed, using the user's bank details
        $recipientResponse = Http::withToken(config('services.paystack.secret_key'))
            ->post('https://api.paystack.co/transferrecipient', [
                'type' => 'nuban',
                'name' => $user->name,
                'account_number' => $user->bank_account_number,
                'bank_code' => $user->bank_code,
                'currency' => 'NGN',
            ]);

        if (!$recipientResponse->successful()) {
            throw new \Exception('Failed to create transfer recipient');
        }

        $recipientCode = $recipientResponse->json()['data']['recipient_code'];

        // Initiate the transfer
        $transferResponse = Http::withToken(config('services.paystack.secret_key'))
            ->post('https://api.paystack.co/transfer', [
                'source' => 'balance',
                'amount' => $amount * 100, // Amount in kobo
                'recipient' => $recipientCode,
                'reference' => $referenceCode,
            ]);

        if (!$transferResponse->successful()) {
            throw new \Exception('Transfer initiation failed');
        }
    }

    // Handle the webhook callback from Paystack for transfer updates
    public function handlePaystackCallback(Request $request)
    {
        $reference = $request->input('data.reference');
        $status = $request->input('data.status');

        $withdrawal = Withdrawal::where('reference', $reference)->first();

        if (!$withdrawal) {
            return response()->json(['error' => 'Withdrawal not found'], 404);
        }

        DB::transaction(function () use ($withdrawal, $status) {
            if ($status == 'success') {
                $withdrawal->status = 'completed';
                $withdrawal->user->notify(new WithdrawalConfirmed($withdrawal));
            } elseif ($status == 'failed') {
                // Re-credit the user’s balance on failure
                $withdrawal->user->increment('balance', $withdrawal->amount);
                $withdrawal->status = 'failed';
            }

            $withdrawal->save();
        });

        return response()->json(['status' => 'Callback handled']);
    }

    // Method to get the status of a withdrawal
    public function getWithdrawalStatus($reference)
    {
        $withdrawal = Withdrawal::where('reference', $reference)->firstOrFail();
        return response()->json([
            'status' => $withdrawal->status,
            'amount' => $withdrawal->amount,
            'requested_at' => $withdrawal->created_at,
            'completed_at' => $withdrawal->updated_at,
        ]);
    }
}
