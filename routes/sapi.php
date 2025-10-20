<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\LandController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WithdrawalController;

// Public routes
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/email/verify/code', [AuthController::class, 'verifyEmailCode']);
    Route::post('/email/resend-verification', [AuthController::class, 'resendVerificationEmail']);

    Route::prefix('password')->group(function () {
        Route::post('/reset/code', [AuthController::class, 'sendPasswordResetCode']);
        Route::post('/reset/verify', [AuthController::class, 'verifyResetCode']);
        Route::post('/reset', [AuthController::class, 'resetPassword']);
    });
});

// Deposit callback
Route::get('/deposit/callback', [DepositController::class, 'handleDepositCallback'])->name('deposit.callback');

// Protected routes
Route::middleware('jwt.auth')->group(function () {
    Route::middleware('verified')->group(function () {
        // User actions
        Route::post('/logout', [AuthController::class, 'logout']);

        // Land routes
        Route::prefix('lands')->group(function () {
            Route::get('/', [LandController::class, 'index']);
            Route::get('/{id}', [LandController::class, 'show'])->where('id', '[0-9]+');
            Route::post('/', [LandController::class, 'store']);
            Route::post('/{id}/purchase', [PurchaseController::class, 'purchase'])->where('id', '[0-9]+');
            Route::post('/{id}/sell', [PurchaseController::class, 'sellUnits'])->where('id', '[0-9]+');
            Route::get('/{id}/units', [UserController::class, 'getUserUnitsForLand'])->where('id', '[0-9]+');
        });

        Route::get('/user/lands', [UserController::class, 'getAllUserLands']);

        // Deposit and withdrawal
        Route::post('/deposit', [DepositController::class, 'initiateDeposit']);
        Route::post('/withdraw', [WithdrawalController::class, 'initiateWithdrawal']);
    });
});
