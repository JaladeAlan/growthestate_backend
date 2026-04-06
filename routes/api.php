<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PinController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\LandController;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\WithdrawalController;
use App\Http\Controllers\PortfolioController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\KycController;
use App\Http\Controllers\KycImageController;
use App\Http\Controllers\ReferralController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AdminSupportController;
use App\Http\Controllers\MarketplaceController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\WaitlistController;

use App\Http\Controllers\PaystackWebhookController;
use App\Http\Controllers\MonnifyWebhookController;
use App\Http\Controllers\OpayWebhookController;

// =============================================================================
// PUBLIC — no authentication required
// =============================================================================

// ── Auth ──────────────────────────────────────────────────────────────────────

// Registration: 5 per hour per IP (stops bulk account creation + email spam)
Route::post('/register', [AuthController::class, 'register'])
    ->middleware('throttle:5,60');

// Login: 10 per 5 minutes per IP (generous enough for real users, blocks brutes)
// The controller applies a tighter per-email+IP limiter on top of this.
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:10,5');

// Email verification: 3 per 15 minutes (ThrottleSensitiveRequests in controller)
Route::post('/email/verify/code', [AuthController::class, 'verifyEmailCode'])
    ->middleware('throttle:3,15');

// Resend verification: 3 per 15 minutes per email+IP (controller-level)
Route::post('/email/resend-verification', [AuthController::class, 'resendVerification'])
    ->middleware('throttle:3,15');

// Password reset flow: each step is also rate-limited at controller level
Route::post('/password/reset/code',   [AuthController::class, 'sendPasswordResetCode'])
    ->middleware('throttle:3,15');
Route::post('/password/reset/verify', [AuthController::class, 'verifyPasswordResetCode'])
    ->middleware('throttle:3,15');
Route::post('/password/reset',        [AuthController::class, 'resetPassword'])
    ->middleware('throttle:3,15');

// ── Public land listing ───────────────────────────────────────────────────────
Route::get('/land', [LandController::class, 'index']);

// ── Public referral code validation ──────────────────────────────────────────
Route::post('/referrals/validate', [ReferralController::class, 'validateCode'])
    ->middleware('throttle:20,1');

// ── Public support ────────────────────────────────────────────────────────────
Route::get('/support/faqs', [SupportController::class, 'faqs']);

// Guest ticket: 5 per 10 minutes per IP (also enforced inside the controller)
Route::post('/support/tickets/guest', [SupportController::class, 'storeGuestTicket'])
    ->middleware('throttle:5,10');

// ── Public blog ───────────────────────────────────────────────────────────────
Route::prefix('blog')->group(function () {
    Route::get('/',              [BlogController::class, 'index']);
    Route::get('/categories',   [BlogController::class, 'categories']);
    Route::get('/tags',         [BlogController::class, 'tags']);
    Route::get('/{slug}',       [BlogController::class, 'show']);
});

// ── Waitlist ──────────────────────────────────────────────────────────────────
Route::post('/waitlist',       [WaitlistController::class, 'store'])->middleware('throttle:5,10');
Route::post('/waitlist/check', [WaitlistController::class, 'check'])->middleware('throttle:10,1');

// ── Certificate public verification ──────────────────────────────────────────
Route::get('/verify/{certNumber}', [CertificateController::class, 'verify'])
    ->middleware('throttle:30,1');

// ── Payment webhooks (signature-verified internally, not JWT-authenticated) ───
Route::post('/paystack/webhook', [PaystackWebhookController::class, 'handle']);
Route::post('/monnify/webhook',  [MonnifyWebhookController::class,  'handle']);
Route::post('/opay/webhook',     [OpayWebhookController::class,     'handle']);

// =============================================================================
// AUTHENTICATED — requires valid JWT
// =============================================================================

Route::middleware(['jwt.auth'])->group(function () {

    // ── Auth ──────────────────────────────────────────────────────────────────
    Route::post('/logout',  [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh'])->middleware('throttle:10,1');

    Route::post('/user/change-password', [AuthController::class, 'changePassword'])
        ->middleware('throttle:5,15');

    // ── Requires verified email ───────────────────────────────────────────────
    Route::middleware(['verified'])->group(function () {

        // ── Profile ───────────────────────────────────────────────────────────
        Route::get('/me',                    [ProfileController::class, 'me']);
        Route::get('/user/account-status',   [ProfileController::class, 'accountStatus']);
        Route::get('/user/stats',            [ProfileController::class, 'stats']);
        Route::get('/user/lands',            [ProfileController::class, 'lands']);
        Route::put('/user/bank-details',     [ProfileController::class, 'updateBankDetails']);

        // ── Transaction PIN ───────────────────────────────────────────────────
        Route::post('/pin/set',    [PinController::class, 'set']);
        Route::post('/pin/update', [PinController::class, 'update']);

        Route::post('/pin/forgot',       [PinController::class, 'forgot'])
            ->middleware('throttle:5,15');
        Route::post('/pin/verify-code',  [PinController::class, 'verifyCode'])
            ->middleware('throttle:5,15');
        Route::post('/pin/reset',        [PinController::class, 'reset'])
            ->middleware('throttle:5,15');

        // ── Lands (authenticated) ─────────────────────────────────────────────
        Route::get('/lands',          [LandController::class, 'indexAuth']);
        Route::get('/lands/map',      [LandController::class, 'mapIndex']);
        Route::get('/lands/{land}',   [LandController::class, 'show']);
        Route::get('/lands/{land}/units', [LandController::class, 'units']);

        // ── Deposits ──────────────────────────────────────────────────────────
        Route::post('/deposit', [DepositController::class, 'initiateDeposit'])
            ->middleware('throttle:10,60');
        Route::get('/deposit/verify/{reference}', [DepositController::class, 'verifyDeposit']);
        Route::get('/paystack/banks',             [DepositController::class, 'banks']);
        Route::post('/paystack/resolve-account',  [DepositController::class, 'resolveAccount'])
            ->middleware('throttle:20,1');

        // ── Withdrawals ───────────────────────────────────────────────────────
        Route::post('/withdraw',                    [WithdrawalController::class, 'requestWithdrawal'])
            ->middleware('throttle:5,60');
        Route::get('/withdrawals/{reference}',      [WithdrawalController::class, 'getWithdrawalStatus']);

        // ── Transactions ──────────────────────────────────────────────────────
        Route::get('/transactions/user',            [TransactionController::class, 'index']);
        Route::post('/lands/{land}/purchase',       [TransactionController::class, 'purchase']);
        Route::post('/lands/{land}/sell',           [TransactionController::class, 'sell']);

        // ── Portfolio ─────────────────────────────────────────────────────────
        Route::get('/portfolio/summary',            [PortfolioController::class, 'summary']);
        Route::get('/portfolio/chart',              [PortfolioController::class, 'chart']);
        Route::get('/portfolio/performance',        [PortfolioController::class, 'performance']);
        Route::get('/portfolio/allocation',         [PortfolioController::class, 'allocation']);
        Route::get('/portfolio/asset/{land}',       [PortfolioController::class, 'asset']);

        // ── KYC ───────────────────────────────────────────────────────────────
        Route::get('/kyc/status',                   [KycController::class, 'status']);
        Route::post('/kyc/submit',                  [KycController::class, 'submit'])
            ->middleware('throttle:3,60'); // 3 submissions per hour
        Route::get('/kyc/{id}/image/{type}',        [KycImageController::class, 'show']);

        // ── Referrals ─────────────────────────────────────────────────────────
        Route::get('/referrals/dashboard',              [ReferralController::class, 'dashboard']);
        Route::get('/referrals/rewards',                [ReferralController::class, 'availableRewards']);
        Route::post('/referrals/rewards/{id}/claim',    [ReferralController::class, 'claimReward'])
            ->middleware('throttle:10,1');

        // ── Notifications ─────────────────────────────────────────────────────
        Route::get('/notifications',                [NotificationController::class, 'index']);
        Route::get('/notifications/unread',         [NotificationController::class, 'unread']);
        Route::post('/notifications/read',          [NotificationController::class, 'markAllRead']);
        Route::post('/notifications/{id}/read',     [NotificationController::class, 'markRead']);

        // ── Support ───────────────────────────────────────────────────────────
        // AI chat: 20 per 10 min per user (also enforced in controller)
        Route::post('/support/chat', [SupportController::class, 'chat'])
            ->middleware('throttle:20,10');
        Route::get('/support/tickets',                          [SupportController::class, 'indexTickets']);
        Route::post('/support/tickets',                         [SupportController::class, 'storeTicket'])
            ->middleware('throttle:5,60');
        Route::get('/support/tickets/{ticket}',                 [SupportController::class, 'showTicket']);
        Route::post('/support/tickets/{ticket}/reply',          [SupportController::class, 'replyTicket'])
            ->middleware('throttle:20,10');
        Route::patch('/support/tickets/{ticket}/close',         [SupportController::class, 'closeTicket']);

        // ── Marketplace ───────────────────────────────────────────────────────
        Route::get('/marketplace',                                          [MarketplaceController::class, 'index']);
        Route::get('/marketplace/my-listings',                              [MarketplaceController::class, 'myListings']);
        Route::get('/marketplace/my-offers',                                [MarketplaceController::class, 'myOffers']);
        Route::get('/marketplace/my-transactions',                          [MarketplaceController::class, 'myTransactions']);
        Route::get('/marketplace/{listing}',                                [MarketplaceController::class, 'show']);
        Route::post('/marketplace',                                         [MarketplaceController::class, 'store'])
            ->middleware('throttle:10,60');
        Route::patch('/marketplace/{listing}',                              [MarketplaceController::class, 'update']);
        Route::delete('/marketplace/{listing}',                             [MarketplaceController::class, 'destroy']);
        Route::post('/marketplace/{listing}/offers',                        [MarketplaceController::class, 'makeOffer'])
            ->middleware('throttle:10,60');
        Route::patch('/marketplace/{listing}/offers/{offer}/accept',        [MarketplaceController::class, 'acceptOffer']);
        Route::patch('/marketplace/{listing}/offers/{offer}/reject',        [MarketplaceController::class, 'rejectOffer']);
        Route::patch('/marketplace/{listing}/offers/{offer}/withdraw',      [MarketplaceController::class, 'withdrawOffer']);
        Route::get('/marketplace/{listing}/messages',                       [MarketplaceController::class, 'messages']);
        Route::post('/marketplace/{listing}/messages',                      [MarketplaceController::class, 'sendMessage'])
            ->middleware('throttle:30,1');

        // ── Certificates ──────────────────────────────────────────────────────
        Route::get('/certificates',                         [CertificateController::class, 'index']);
        Route::get('/certificates/{certNumber}',            [CertificateController::class, 'show']);
        Route::get('/certificates/{certNumber}/download',   [CertificateController::class, 'download'])
            ->middleware('throttle:10,1');
    });
});

// =============================================================================
// ADMIN — requires JWT + admin flag
// =============================================================================

Route::middleware(['jwt.auth', 'admin'])->prefix('admin')->group(function () {

    // ── Users ─────────────────────────────────────────────────────────────────
    Route::get('/users',                        [AdminUserController::class, 'index']);
    Route::get('/users/{user}',                 [AdminUserController::class, 'show']);
    Route::patch('/users/{user}/suspend',       [AdminUserController::class, 'suspend']);
    Route::patch('/users/{user}/unsuspend',     [AdminUserController::class, 'unsuspend']);
    Route::patch('/users/{user}/make-admin',    [AdminUserController::class, 'makeAdmin']);
    Route::patch('/users/{user}/remove-admin',  [AdminUserController::class, 'removeAdmin']);
    Route::delete('/users/{user}',              [AdminUserController::class, 'destroy']);

    // ── Lands ─────────────────────────────────────────────────────────────────
    Route::get('/lands',                        [LandController::class, 'adminIndex']);
    Route::post('/lands',                       [LandController::class, 'store']);
    Route::post('/lands/{land}',                [LandController::class, 'update']);
    Route::patch('/lands/{land}/price',         [LandController::class, 'updatePrice']);
    Route::patch('/lands/{land}/availability',  [LandController::class, 'toggleAvailability']);

    // Land valuations
    Route::get('/lands/{land}/valuation',                       [LandController::class, 'getValuations']);
    Route::post('/lands/{land}/valuation',                      [LandController::class, 'addValuationEntry']);
    Route::patch('/lands/{land}/valuation/{year}/{month}',      [LandController::class, 'updateValuationEntry']);
    Route::delete('/lands/{land}/valuation/{year}/{month}',     [LandController::class, 'deleteValuationEntry']);

    // ── KYC ───────────────────────────────────────────────────────────────────
    Route::get('/kyc',                          [KycController::class, 'adminIndex']);
    Route::get('/kyc/{id}',                     [KycController::class, 'adminShow']);
    Route::post('/kyc/{id}/approve',            [KycController::class, 'adminApprove']);
    Route::post('/kyc/{id}/reject',             [KycController::class, 'adminReject']);
    Route::post('/kyc/{id}/resubmit',           [KycController::class, 'adminRequestResubmit']);

    // ── Support tickets ───────────────────────────────────────────────────────
    Route::get('/support/tickets',                              [AdminSupportController::class, 'index']);
    Route::get('/support/tickets/{ticket}',                     [AdminSupportController::class, 'show']);
    Route::post('/support/tickets/{ticket}/reply',              [AdminSupportController::class, 'reply']);
    Route::patch('/support/tickets/{ticket}/status',            [AdminSupportController::class, 'updateStatus']);
    Route::delete('/support/tickets/{ticket}',                  [AdminSupportController::class, 'destroy']);
    Route::get('/support/stats',                                [SupportController::class, 'adminStats']);

    // Posts
    Route::get('/blog',                  [BlogController::class, 'adminIndex']);
    Route::get('/blog/{blogPost}',        [BlogController::class, 'adminShow']);
    Route::post('/blog',                  [BlogController::class, 'store']);
    Route::post('/blog/{blogPost}',       [BlogController::class, 'update']);
    Route::delete('/blog/{blogPost}',     [BlogController::class, 'destroy']);

    // Categories
    Route::post('/blog/categories',                       [BlogController::class, 'storeCategory']);
    Route::patch('/blog/categories/{blogCategory}',       [BlogController::class, 'updateCategory']);
    Route::delete('/blog/categories/{blogCategory}',      [BlogController::class, 'destroyCategory']);

    // Tags
    Route::post('/blog/tags',                             [BlogController::class, 'storeTag']);
    Route::delete('/blog/tags/{blogTag}',                 [BlogController::class, 'destroyTag']);

    // ── Withdrawals ───────────────────────────────────────────────────────────
    Route::post('/withdrawals/retry', [WithdrawalController::class, 'retryPendingWithdrawals']);

    // ── Referrals ─────────────────────────────────────────────────────────────
    Route::get('/referrals',        [ReferralController::class, 'adminIndex']);
    Route::get('/referrals/stats',  [ReferralController::class, 'adminStats']);

    // ── Certificates ──────────────────────────────────────────────────────────
    Route::get('/certificates',                         [CertificateController::class, 'adminIndex']);
    Route::patch('/certificates/{certificate}/revoke',  [CertificateController::class, 'revoke']);
    Route::post('/certificates/{certificate}/regenerate', [CertificateController::class, 'regenerate']);

    // ── Waitlist ──────────────────────────────────────────────────────────────
    Route::get('/waitlist',                 [WaitlistController::class, 'index']);
    Route::get('/waitlist/stats',           [WaitlistController::class, 'stats']);
    Route::post('/waitlist/{waitlist}/invite', [WaitlistController::class, 'invite']);
    Route::delete('/waitlist/{waitlist}',   [WaitlistController::class, 'destroy']);
});