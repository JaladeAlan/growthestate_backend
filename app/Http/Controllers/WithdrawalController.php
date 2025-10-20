<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Withdrawal;
use App\Notifications\WithdrawalConfirmed;
use App\Notifications\WithdrawalFailedNotification;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

class WithdrawalController extends Controller
{
    // Request Withdrawal
    public function requestWithdrawal(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();

        $request->validate([
            'amount' => 'required|numeric|min:1',
        ]);

        if ($user->balance < $request->amount) {
            return response()->json(['error' => 'Insufficient balance'], 400);
        }

        if (!$user->account_number || !$user->bank_code) {
            return response()->json(['error' => 'Bank details are missing'], 400);
        }

        // if (Withdrawal::where('user_id', $user->id)->where('status', 'pending')->exists()) {
        //     return response()->json(['error' => 'You already have a pending withdrawal'], 400);
        // }

        try {
            $referenceCode = 'WD-' . now()->format('Ymd-His') . '-' . Str::random(6);

            // Log balance before withdrawal
            Log::info('User balance before withdrawal', [
                'user_id' => $user->id,
                'balance' => $user->balance,
                'withdrawal_amount' => $request->amount,
            ]);

            DB::transaction(function () use ($user, $request, $referenceCode) {
                $user->decrement('balance', $request->amount);

                Withdrawal::create([
                    'user_id' => $user->id,
                    'amount' => $request->amount,
                    'status' => 'pending',
                    'reference' => $referenceCode,
                ]);
            });

              // Log balance after withdrawal
            Log::info('User balance after withdrawal', [
                'user_id' => $user->id,
                'balance' => $user->fresh()->balance, 
            ]);

            Log::info('Withdrawal initiated', [
                'user_id' => $user->id,
                'account_number' => $user->account_number,
                'bank_code' => $user->bank_code,
            ]);

            if (config('services.paystack.test_mode')) {
                return $this->simulateTestWithdrawal($referenceCode);
            } else {
                $this->initiatePaystackTransfer($user, $request->amount, $referenceCode);
            }

            return response()->json(['message' => 'Withdrawal request initiated', 'reference' => $referenceCode], 200);
        } catch (\Exception $e) {
            Log::error('Withdrawal request failed', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
                'amount' => $request->amount,
            ]);
            return response()->json(['error' => 'Failed to initiate withdrawal'], 500);
        }
    }

    protected function simulateTestWithdrawal($referenceCode)
    {
        Log::info('Simulating test mode withdrawal', ['reference' => $referenceCode]);

        $this->handlePaystackCallback(new Request([
            'data' => [
                'reference' => $referenceCode,
                'status' => 'success',
            ]
        ]));

        return response()->json([
            'message' => 'Test mode withdrawal successful',
            'reference' => $referenceCode,
            'status' => 'success',
        ], 200);
    }

    protected function initiatePaystackTransfer($user, $amount, $referenceCode)
    {
        Log::info('Initiating transfer with Paystack', [
            'account_number' => $user->account_number,
            'bank_code' => $user->bank_code,
            'amount' => $amount,
            'reference' => $referenceCode,
        ]);

        $recipientCode = $this->getOrCreatePaystackRecipient($user);

        $transferResponse = Http::withToken(config('services.paystack.secret_key'))
            ->post('https://api.paystack.co/transfer', [
                'source' => 'balance',
                'amount' => $amount * 100,
                'recipient' => $recipientCode,
                'reference' => $referenceCode,
            ]);

        if (!$transferResponse->successful()) {
            throw new \Exception('Transfer initiation failed: ' . $transferResponse->json()['message']);
        }
    }

    protected function getOrCreatePaystackRecipient($user)
    {
        return $user->recipient_code ?? $this->createPaystackRecipient($user);
    }

    protected function createPaystackRecipient($user)
    {
        $recipientResponse = Http::withToken(config('services.paystack.secret_key'))
            ->post('https://api.paystack.co/transferrecipient', [
                'type' => 'nuban',
                'name' => $user->name,
                'account_number' => $user->account_number,
                'bank_code' => $user->bank_code,
                'currency' => 'NGN',
            ]);

        if (!$recipientResponse->successful()) {
            throw new \Exception('Failed to create transfer recipient: ' . $recipientResponse->json()['message']);
        }

        $recipientCode = $recipientResponse->json()['data']['recipient_code'];
        $user->update(['recipient_code' => $recipientCode]);

        return $recipientCode;
    }

   public function handlePaystackCallback(Request $request)
    {
        if (!config('services.paystack.test_mode')) {
            $payload = file_get_contents('php://input'); 
            $signature = $request->header('x-paystack-signature');

            if (!hash_equals(hash_hmac('sha512', $payload, config('services.paystack.webhook_secret')), $signature)) {
                Log::warning('Invalid Paystack webhook signature');
                abort(403, 'Unauthorized webhook');
            }
        }

        $reference = $request->input('data.reference');
        $status = $request->input('data.status');

        $withdrawal = Withdrawal::firstWhere('reference', $reference);

        if (!$withdrawal) {
            return response()->json(['error' => 'Withdrawal not found'], 404);
        }

        DB::transaction(function () use ($withdrawal, $status) {
            if ($withdrawal->status === 'completed') {
                return;
            }

            if ($status === 'success') {
                $withdrawal->update(['status' => 'completed']);

                try {
                    Log::info("Sending withdrawal confirmation notification", [
                        'user_id' => $withdrawal->user->id,
                        'amount' => $withdrawal->amount,
                        'withdrawal_reference' => $withdrawal->reference,
                    ]);
                
                    $notification = new WithdrawalConfirmed($withdrawal);
                    $withdrawal->user->notify($notification);
                
                    Log::info("Withdrawal confirmation notification sent", [
                        'notification_data' => $notification->toDatabase($withdrawal->user),
                    ]);
                } catch (\Exception $e) {
                    Log::error("Failed to send withdrawal confirmation notification", [
                        'error' => $e->getMessage(),
                        'user_id' => $withdrawal->user->id,
                        'amount' => $withdrawal->amount,
                        'withdrawal_reference' => $withdrawal->reference,
                    ]);
                }                
            } elseif ($status === 'failed') {
                $withdrawal->user->increment('balance', $withdrawal->amount);
                $withdrawal->update(['status' => 'failed']);

                try {
                    Log::info("Sending withdrawal failure notification", [
                        'user_id' => $withdrawal->user->id,
                        'amount' => $withdrawal->amount,
                        'withdrawal_reference' => $withdrawal->reference,
                    ]);
                
                    $notification = new WithdrawalFailedNotification($withdrawal);
                    $withdrawal->user->notify($notification);
                
                    Log::info("Withdrawal failure notification sent", [
                        'notification_data' => $notification->toDatabase($withdrawal->user),
                    ]);
                } catch (\Exception $e) {
                    Log::error("Failed to send withdrawal failure notification", [
                        'error' => $e->getMessage(),
                        'user_id' => $withdrawal->user->id,
                        'amount' => $withdrawal->amount,
                        'withdrawal_reference' => $withdrawal->reference,
                    ]);
                }                
            } else {
                Log::warning('Unexpected Paystack withdrawal status', ['status' => $status]);
            }
        });

        return response()->json(['status' => 'Callback handled']);
    }

    public function getWithdrawalStatus($reference)
    {
        Log::info("Checking withdrawal status for reference: " . $reference);
    
        $withdrawal = Withdrawal::firstWhere('reference', $reference);
    
        if (!$withdrawal) {
            Log::error("Withdrawals not found", ['reference' => $reference]);
            return response()->json(['error' => 'Withdrawalsss not found'], 404);
        }
    
        return response()->json([
            'status' => $withdrawal->status,
            'amount' => $withdrawal->amount,
            'requested_at' => $withdrawal->created_at,
            'completed_at' => $withdrawal->updated_at,
        ], 200);
    }   

    public function retryPendingWithdrawals()
    {
        $pendingWithdrawals = Withdrawal::where('status', 'pending')->get();
    
        if ($pendingWithdrawals->isEmpty()) {
            Log::info('No pending withdrawals to retry');
            return response()->json(['message' => 'No pending withdrawals to retry'], 200);
        }
    
        Log::info('Pending withdrawals found', [
            'withdrawals' => $pendingWithdrawals->map(function ($withdrawal) {
                return [
                    'id' => $withdrawal->id,
                    'user_id' => $withdrawal->user_id,
                    'amount' => $withdrawal->amount,
                    'reference' => $withdrawal->reference,
                    'status' => $withdrawal->status
                ];
            })
        ]);
    
        foreach ($pendingWithdrawals as $withdrawal) {
            if (!$withdrawal->reference) {
                Log::error('Skipping withdrawal due to missing reference', [
                    'withdrawal_id' => $withdrawal->id
                ]);
                continue;
            }
    
            // Ensure the withdrawal exists before proceeding
            $existingWithdrawal = Withdrawal::where('reference', $withdrawal->reference)->first();
            if (!$existingWithdrawal) {
                Log::error("Withdrawal not found in database", [
                    'reference' => $withdrawal->reference
                ]);
                continue;
            }
    
            try {
                Log::info('Retrying withdrawal', [
                    'withdrawal_id' => $withdrawal->id,
                    'user_id' => $withdrawal->user_id,
                    'amount' => $withdrawal->amount,
                    'reference' => $withdrawal->reference
                ]);
    
                // Re-trigger the withdrawal attempt
                $this->initiatePaystackTransfer($withdrawal->user, $withdrawal->amount, $withdrawal->reference);
    
                Log::info('Withdrawal retried successfully', ['reference' => $withdrawal->reference]);
    
            } catch (\Exception $e) {
                Log::error('Failed to retry withdrawal', [
                    'withdrawal_id' => $withdrawal->id,
                    'reference' => $withdrawal->reference,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    
        return response()->json(['message' => 'Pending withdrawals retried'], 200);
    }    
      
}