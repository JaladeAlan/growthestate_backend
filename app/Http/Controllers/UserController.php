<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\Land;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class UserController extends Controller
{
    // Retrieve lands and units owned by the user
    public function getUserUnitsForLand(Request $request, $landId)
    {
        try {
            // Get the authenticated user from the JWT token
            $user = JWTAuth::parseToken()->authenticate();
        } catch (\Exception $e) {
            return response()->json(['error' => 'User not authenticated'], 401);
        }

        // Query the purchases table for the user's units for the specific land
        $purchase = Purchase::where('user_id', $user->id)
                            ->where('land_id', $landId)
                            ->first();

        // Logging if desired
        Log::info("User {$user->id} queried units for land {$landId}", [
            'units_owned' => $purchase ? $purchase->units : 0,
        ]);

        return response()->json([
            'land_id' => $landId,
            'units_owned' => $purchase ? $purchase->units : 0,
        ]);
    }

    // Retrieve all lands and units owned by the user
    public function getAllUserLands(Request $request)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
        } catch (\Exception $e) {
            return response()->json(['error' => 'User not authenticated'], 401);
        }

        // Fetch all lands and units the user owns from purchases
        $purchases = Purchase::with('land')
                             ->where('user_id', $user->id)
                             ->get();

        // Prepare response data
        $ownedLands = $purchases->map(function ($purchase) {
            return [
                'land_id' => $purchase->land->id,
                'land_name' => $purchase->land->title,
                'units_owned' => $purchase->units,
            ];
        });

        return response()->json(['owned_lands' => $ownedLands]);
    }

      // Update user's bank details using Paystack API
      public function updateBankDetails(Request $request)
      {
          $user = JWTAuth::parseToken()->authenticate();
      
          // Validate the incoming data
          $request->validate([
              'account_number' => 'required|numeric|digits_between:10,12', // Adjust length for your region
              'bank_name' => 'required|string',
          ]);
      
          try {
              // Step 1: Fetch bank codes from Paystack (Cache for efficiency)
              $banks = Cache::remember('paystack_banks', now()->addHours(12), function () {
                  $response = Http::withToken(config('services.paystack.secret_key'))
                      ->get('https://api.paystack.co/bank');
      
                  return $response->successful() ? $response->json()['data'] : [];
              });
      
              $bank = collect($banks)->firstWhere('name', $request->bank_name);
      
              if (!$bank) {
                  return response()->json(['error' => 'Invalid bank name provided'], 400);
              }
      
              $bankCode = $bank['code'];
      
              // Step 2: Resolve account number with Paystack
              $resolveResponse = Http::withToken(config('services.paystack.secret_key'))
                  ->get('https://api.paystack.co/bank/resolve', [
                      'account_number' => $request->account_number,
                      'bank_code' => $bankCode,
                  ]);
      
              if (!$resolveResponse->successful()) {
                  return response()->json([
                      'error' => 'Failed to resolve account number',
                      'details' => $resolveResponse->json(),
                  ], 400);
              }
      
              $resolvedData = $resolveResponse->json();
              if (!isset($resolvedData['data']['account_name'])) {
                  return response()->json(['error' => 'Invalid account details returned'], 400);
              }
      
              $accountName = $resolvedData['data']['account_name'];

              \Log::info('Updating bank details:', [
                'account_number' => $request->account_number,
                'bank_code' => $bankCode,
                'bank_name' => $request->bank_name,
                'account_name' => $accountName,
            ]);
      
              // Step 3: Update the user's bank details
              $user->update([
                  'account_number' => $request->account_number,
                  'bank_code' => $bankCode,
                  'bank_name' => $request->bank_name,
                  'account_name' => $accountName,
              ]);
      
              return response()->json(['message' => 'Bank details updated successfully'], 200);
          } catch (\Exception $e) {
              return response()->json([
                  'error' => 'An error occurred while updating bank details',
                  'message' => $e->getMessage(),
              ], 500);
          }
}
}