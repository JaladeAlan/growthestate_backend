<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\LandController;
// use App\Http\Controllers\TransactionController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VerificationController;
use App\Http\Controllers\WithdrawalController;

// Public routes
Route::post('/register', [AuthController::class, 'register']); // User registration
Route::post('login', [AuthController::class, 'login']);

// Email verification routes
Route::post('/email/verify/code', [AuthController::class, 'verifyEmailCode']); 
Route::post('/email/resend-verification', [AuthController::class, 'resendVerificationEmail']);

// Deposit callback route (signed to prevent unauthorized access)
Route::get('/deposit/callback', [DepositController::class, 'handleDepositCallback'])->name('deposit.callback');

// Protected routes - requires user authentication via JWT
Route::middleware('auth:api')->group(function () {

    // Email verified routes (only accessible if email is verified)
    Route::middleware('verified')->group(function () {

        // Logout route
        Route::post('/logout', [AuthController::class, 'logout']); // User logout

        // Land management routes
        Route::prefix('lands')->group(function () {
            Route::get('/', [LandController::class, 'index']); // Get all lands
            Route::get('/{id}', [LandController::class, 'show']); // Get a specific land by ID
            Route::post('/', [LandController::class, 'store']); // Create a new land
            Route::post('/{id}/purchase', [PurchaseController::class, 'purchase']); // Purchase units of land
            Route::post('/{id}/sell', [PurchaseController::class, 'sellUnits']); // Sell units of land
            Route::get('/{id}/units', [UserController::class, 'getUserUnitsForLand']); // Get units owned by the user for a specific land
        });

        // Route to get all lands and units owned by the user
        Route::get('/user/lands', [UserController::class, 'getAllUserLands']);

        // Transaction management routes
        // Route::prefix('transactions')->group(function () {
        //     Route::get('/', [TransactionController::class, 'index']); // Get all transactions
        //     Route::get('/users/{user_id}', [TransactionController::class, 'getByUser']); // Get transactions by user ID
        //     Route::get('/lands/{land_id}', [TransactionController::class, 'getByLand']); // Get transactions by land ID
        // });

        // Deposit and withdrawal routes
        Route::post('/deposit', [DepositController::class, 'initiateDeposit']); // Deposit funds
        Route::post('/withdraw', [WithdrawalController::class, 'initiateWithdrawal']); // Withdraw funds
    });
});
