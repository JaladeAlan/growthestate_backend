<?php

namespace App\Http\Controllers;

use App\Models\Deposit;
use App\Services\Payments\DepositService;
use App\Services\Payments\MonnifyService;
use App\Services\Payments\PaystackService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class DepositController extends Controller
{
    public function initiateDeposit(Request $request)
    {
        $user = $request->user();

        $request->validate([
            'amount'  => 'required|integer|min:100000|max:1000000000',
            'gateway' => 'required|in:monnify,paystack',
        ]);

        try {
            $deposit = DepositService::createDepositKobo(
                $user,
                $request->amount,
                $request->gateway
            );
        } catch (\Exception $e) {
            Log::error('Deposit record creation failed', [
                'user_id' => $user->id,
                'amount'  => $request->amount,
                'gateway' => $request->gateway,
                'error'   => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Could not create deposit. Please try again.'], 500);
        }

        $callbackUrl = config('app.frontend_url') . "/wallet?reference={$deposit->reference}";

        try {
            if ($deposit->gateway === 'monnify') {
              $response = MonnifyService::initialize(
                    $user->email,
                    $deposit->reference,
                    $deposit->total_kobo,
                    $callbackUrl,
                    $user->name
                );

                $paymentUrl = $response['responseBody']['checkoutUrl'] ?? null;
            } else { // paystack
                $response = PaystackService::initialize(
                    $user->email,
                    $deposit->total_kobo,
                    $deposit->reference,
                    $callbackUrl
                );

                $paymentUrl = $response['data']['authorization_url'] ?? null;
            }
        } catch (\Exception $e) {
            $deposit->delete();

            Log::error('Gateway initialization failed', [
                'user_id'   => $user->id,
                'reference' => $deposit->reference,
                'gateway'   => $deposit->gateway,
                'error'     => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Payment gateway error. Please try again.'], 502);
        }

        if (! $paymentUrl) {
            $deposit->delete();

            Log::warning('Gateway returned no payment URL', [
                'user_id'   => $user->id,
                'reference' => $deposit->reference,
                'gateway'   => $deposit->gateway,
            ]);

            return response()->json(['error' => 'Payment initialization failed.'], 500);
        }

        Log::info('Deposit initiated', [
            'user_id'   => $user->id,
            'reference' => $deposit->reference,
            'gateway'   => $deposit->gateway,
            'amount'    => $request->amount,
        ]);

        return response()->json([
            'payment_url'     => $paymentUrl,
            'reference'       => $deposit->reference,
            'gateway'         => $deposit->gateway,
            'transaction_fee' => $deposit->transaction_fee / 100,
            'total_amount'    => $deposit->total_kobo / 100,
        ]);
    }

    public function verifyDeposit(Request $request, string $reference)
    {
        $deposit = Deposit::where('reference', $reference)
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $deposit) {
            return response()->json(['status' => 'not_found'], 404);
        }

        return response()->json([
            'reference' => $deposit->reference,
            'gateway'   => $deposit->gateway,
            'status'    => $deposit->status,
            'amount'    => $deposit->amount_kobo / 100,
        ]);
    }

   public function banks()
    {
        try {
            $banks = Cache::remember('paystack_banks', now()->addHours(6), function () {
                return PaystackService::getBanks();
            });
        } catch (\Exception $e) {
            Log::error('Failed to fetch banks', [
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Could not fetch banks. Please try again.'], 502);
        }

        return response()->json([
            'banks' => $banks['data'] ?? [],
        ]);
    }

    public function resolveAccount(Request $request)
    {
        $request->validate([
            'account_number' => 'required|string|digits:10',
            'bank_code'      => 'required|string',
        ]);

        try {
            $result = PaystackService::resolveAccount(
                $request->account_number,
                $request->bank_code
            );
        } catch (\Exception $e) {
            Log::error('Account resolution failed', [
                'account_number' => $request->account_number,
                'bank_code'      => $request->bank_code,
                'error'          => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Could not resolve account. Please try again.'], 502);
        }

        return response()->json([
            'account_name'   => $result['data']['account_name'] ?? null,
            'account_number' => $result['data']['account_number'] ?? null,
            'bank_id'        => $result['data']['bank_id'] ?? null,
        ]);
    }
}