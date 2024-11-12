<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\Land;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Log;

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
}
