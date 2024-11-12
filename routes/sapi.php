<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LandController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\TransactionController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| These routes are for your API. They are stateless and don't require CSRF
| protection. By default, they are assigned the "api" middleware group.
|
*/

// Test route to check if the API is working
Route::get('/test', function () {
    return 'API is working';
});

// Route to get all lands
Route::get('/lands', [LandController::class, 'index']);

// Route to get details of a single land by its ID
Route::get('/lands/{id}', [LandController::class, 'show']);

// Route to create a new land
Route::post('/lands', [LandController::class, 'store']);

// Route to purchase shares of a land by its ID
Route::post('/lands/{id}/buy', [LandController::class, 'buy']);

// Route to get all shares for a specific land
Route::get('/lands/{id}/shares', [ShareController::class, 'index']);

// Route to get a specific share by its ID (optional)
Route::get('/shares/{id}', [ShareController::class, 'show']);

// Route to get all transactions
Route::get('/transactions', [TransactionController::class, 'index']);

// Route to get all transactions for a specific user (optional)
Route::get('/users/{user_id}/transactions', [TransactionController::class, 'getByUser']);

// Route to get all transactions for a specific land (optional)
Route::get('/lands/{land_id}/transactions', [TransactionController::class, 'getByLand']);

Route::middleware('auth:sanctum')->get('/user',
function(Request $request){
    return $request->user();
} 
);