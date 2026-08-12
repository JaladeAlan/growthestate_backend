<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PaystackWebhookController;
use App\Http\Controllers\MonnifyWebhookController;
use App\Http\Controllers\OpayWebhookController;

// ─────────────────────────────────────────────────────────────────────────────
// API versioning
//
// Every route currently lives at both /api/... (unprefixed, unchanged — the
// frontend at NEXT_PUBLIC_API_URL=https://api.reu.ng/api keeps working with
// zero changes) and /api/v1/... (identical routes, new). This costs nothing
// today and means the day a breaking change is needed, /v1 can be frozen and
// /v2 introduced without a migration scramble.
//
// routes/api_routes.php holds the actual route definitions and is `require`d
// twice below — once directly (unprefixed) and once inside the v1 group.
// ─────────────────────────────────────────────────────────────────────────────

// ── Payment webhooks (server-to-server, no auth, no CSRF) — unprefixed only ──
Route::post('/paystack/webhook', [PaystackWebhookController::class, 'handle']);
Route::post('/monnify/webhook',  [MonnifyWebhookController::class,  'handle']);
Route::post('/opay/webhook',     [OpayWebhookController::class,     'handle'])->name('opay.webhook');

// ── OPay cashier redirects (no auth — OPay redirects the browser here) ───────
Route::get('/deposit/opay/return', [OpayWebhookController::class, 'returnUrl'])->name('opay.return');
Route::get('/deposit/opay/cancel', [OpayWebhookController::class, 'cancel'])->name('opay.cancel');

// ── Everything else: available at both /api/... and /api/v1/... ─────────────
require __DIR__ . '/api_routes.php';

Route::prefix('v1')->group(function () {
    require __DIR__ . '/api_routes.php';
});