<?php

use Illuminate\Support\Facades\Route;

// ─────────────────────────────────────────────────────────────────────────────
// This file previously carried a full parallel copy of the API (auth,
// deposits, withdrawals, land purchase/sell, etc.) left over from before the
// routes/api_routes.php refactor. It was still live and reachable:
//
//   - POST /lands/{id}/purchase and /lands/{id}/sell called the SAME
//     PurchaseController methods as the real API, but WITHOUT the
//     'check.pin' middleware — the transaction PIN check lives entirely in
//     that middleware, not in the controller, so this let any authenticated
//     + verified user (or anyone holding a leaked access token) buy/sell
//     land with no PIN, no sanctions-screening check, and no suspended-user
//     check. This was the actual security bug — everything else below was
//     just dead weight pointing at controller methods that no longer exist
//     (WithdrawalController@initiateWithdrawal, DepositController@
//     handleDepositCallback, AuthController@resendVerificationEmail all
//     404/500 if hit).
//
// routes/api_routes.php is the real, maintained, middleware-protected API.
// This file should stay limited to non-API concerns (status page, etc.) —
// do not add app routes here again.
// ─────────────────────────────────────────────────────────────────────────────

Route::get('/', function () {
    return response()->json([
        'status'   => 'success',
        'message'  => '🚀 Growth Estate API is live!',
        'api_base' => url('/api'),
    ]);
});