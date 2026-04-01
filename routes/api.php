    <?php

    use App\Http\Controllers\AdminUserController;
    use App\Http\Controllers\AdminSupportController;
    use App\Http\Controllers\AuthController;
    use App\Http\Controllers\DepositController;
    use App\Http\Controllers\KycController;
    use App\Http\Controllers\KycImageController;
    use App\Http\Controllers\LandController;
    use App\Http\Controllers\MonnifyWebhookController;
    use App\Http\Controllers\NotificationController;
    use App\Http\Controllers\PaystackWebhookController;
    use App\Http\Controllers\PinController;
    use App\Http\Controllers\PortfolioController;
    use App\Http\Controllers\ProfileController;
    use App\Http\Controllers\PurchaseController;
    use App\Http\Controllers\ReferralController;
    use App\Http\Controllers\SupportController;
    use App\Http\Controllers\UserController;
    use App\Http\Controllers\TransactionController;
    use App\Http\Controllers\WithdrawalController;
    use App\Http\Controllers\CertificateController;
    use App\Http\Controllers\MarketplaceController;
    use Illuminate\Support\Facades\Route;


    // ─────────────────────────────────────────────────────────────────────────────
    // PUBLIC (no auth)
    // ─────────────────────────────────────────────────────────────────────────────

    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login',    [AuthController::class, 'login']);

    Route::post('/email/verify/code',         [AuthController::class, 'verifyEmailCode']);
    Route::post('/email/resend-verification', [AuthController::class, 'resendVerification']);

    Route::post('/password/reset/code',   [AuthController::class, 'sendPasswordResetCode']);
    Route::post('/password/reset/verify', [AuthController::class, 'verifyPasswordResetCode']);
    Route::post('/password/reset',        [AuthController::class, 'resetPassword']);

    Route::post('/referrals/validate', [ReferralController::class, 'validateCode']);

    Route::get('/land', [LandController::class, 'index']); // public land listing

    Route::get('/support/faqs',           [SupportController::class, 'faqs']);
    Route::post('/support/tickets/guest', [SupportController::class, 'storeGuestTicket']);

    // Public Certificate verification 
    Route::get('/verify/{certNumber}', [CertificateController::class, 'verify']);

    // ── Public browsing ───────────────────────────────────────────────────────
    Route::get('marketplace/',                  [MarketplaceController::class, 'index']);
    Route::get('marketplace/{listing}',         [MarketplaceController::class, 'show']);

    // ─────────────────────────────────────────────────────────────────────────────

    Route::post('/paystack/webhook', [PaystackWebhookController::class, 'handle']);
    Route::post('/monnify/webhook',  [MonnifyWebhookController::class,  'handle']);

    // ─────────────────────────────────────────────────────────────────────────────
    // AUTHENTICATED (JWT required, account must not be suspended)
    // ─────────────────────────────────────────────────────────────────────────────

    Route::middleware(['jwt.auth', 'suspended'])->group(function () {

        Route::post('/refresh', [AuthController::class, 'refresh']);
        Route::post('/logout',  [AuthController::class, 'logout']);

        Route::get('/deposit/verify/{reference}', [DepositController::class, 'verifyDeposit']);

        // ─────────────────────────────────────────────────────────────────────
        // EMAIL VERIFIED
        // ─────────────────────────────────────────────────────────────────────
        Route::middleware('verified')->group(function () {

            // Profile & account
            Route::get('/me',                    [ProfileController::class, 'me']);
            Route::put('/user/bank-details',     [ProfileController::class, 'updateBankDetails']);
            Route::get('/user/stats',            [ProfileController::class, 'stats']);
            Route::get('/user/lands',            [ProfileController::class, 'lands']);
            Route::post('/user/change-password', [AuthController::class,   'changePassword']);
            Route::get('/user/account-status',   [ProfileController::class, 'accountStatus']);

            // Transaction PIN
            Route::post('/pin/set',         [PinController::class, 'set']);
            Route::post('/pin/update',      [PinController::class, 'update']);
            Route::post('/pin/forgot',      [PinController::class, 'forgot'])->middleware('throttle.sensitive');
            Route::post('/pin/verify-code', [PinController::class, 'verifyCode'])->middleware('throttle.sensitive');
            Route::post('/pin/reset',       [PinController::class, 'reset'])->middleware('throttle.sensitive');

            // Transactions
            Route::get('/transactions/user', [TransactionController::class, 'userTransactions']);

            // Lands (authenticated view)
            Route::get('/lands',              [LandController::class, 'indexAuth']);
            Route::get('/lands/map',          [LandController::class, 'mapIndex']);
            Route::get('/lands/{land}',       [LandController::class, 'show']);
            Route::get('/lands/{land}/units', [LandController::class, 'units']);

            // Purchases & sales
            Route::get('/lands/{landId}/purchase/preview', [PurchaseController::class, 'preview']);
            Route::post('/lands/{land}/purchase', [PurchaseController::class, 'purchase'])->middleware('check.pin');
            Route::post('/lands/{land}/sell',     [PurchaseController::class, 'sellUnits'])->middleware('check.pin');

            // Certificates
            Route::prefix('certificates')->group(function () {
                Route::get('/',                          [CertificateController::class, 'index']);
                Route::get('/{certNumber}',              [CertificateController::class, 'show']);
                Route::get('/{certNumber}/download',     [CertificateController::class, 'download']);
            });
            //Marketplace
            
            Route::prefix('marketplace')->group(function () {
 
                  // My activity
                Route::get('/my/listings', [MarketplaceController::class, 'myListings']);
                Route::get('/my/offers',   [MarketplaceController::class, 'myOffers']);
                Route::get('/marketplace/my-transactions', [MarketplaceController::class, 'myTransactions']);

                // Listings CRUD
                Route::post('/',              [MarketplaceController::class, 'store']);
                Route::patch('/{listing}',    [MarketplaceController::class, 'update']);
                Route::delete('/{listing}',   [MarketplaceController::class, 'destroy']);
        
                 // Offers
                Route::post('/{listing}/offers',                        [MarketplaceController::class, 'makeOffer']);
                Route::patch('/{listing}/offers/{offer}/accept',        [MarketplaceController::class, 'acceptOffer'])->middleware('check.pin');
                Route::patch('/{listing}/offers/{offer}/reject',        [MarketplaceController::class, 'rejectOffer']);
                Route::patch('/{listing}/offers/{offer}/withdraw',      [MarketplaceController::class, 'withdrawOffer']);
        
                // Chat
                Route::get('/{listing}/messages',  [MarketplaceController::class, 'messages']);
                Route::post('/{listing}/messages', [MarketplaceController::class, 'sendMessage']);        
            });

            // Portfolio
            Route::prefix('portfolio')->group(function () {
                Route::get('/summary',      [PortfolioController::class, 'summary']);
                Route::get('/chart',        [PortfolioController::class, 'chart']);
                Route::get('/performance',  [PortfolioController::class, 'performance']);
                Route::get('/allocation',   [PortfolioController::class, 'allocation']);
                Route::get('/asset/{land}', [PortfolioController::class, 'asset']);
            });

            // Deposits
            Route::post('/deposit',                  [DepositController::class, 'initiateDeposit']);
            Route::get('/paystack/banks',            [DepositController::class, 'banks']);
            Route::post('/paystack/resolve-account', [DepositController::class, 'resolveAccount']);

            // Withdrawals
            Route::post('/withdraw',               [WithdrawalController::class, 'requestWithdrawal'])->middleware('check.pin');
            Route::get('/withdrawals/{reference}', [WithdrawalController::class, 'getWithdrawalStatus']);

            // KYC
            Route::get('/kyc/status',                 [KycController::class, 'status']);
            Route::post('/kyc/submit',                [KycController::class, 'submit']);
            Route::get('/kyc/{id}/image/{imageType}', [KycImageController::class, 'show'])->middleware('throttle:30,1');

            // Referrals
            Route::prefix('referrals')->group(function () {
                Route::get('/dashboard',           [ReferralController::class, 'dashboard']);
                Route::get('/rewards',             [ReferralController::class, 'availableRewards']);
                Route::post('/rewards/{id}/claim', [ReferralController::class, 'claimReward']);
            });

            // Support (authenticated)
            Route::post('/support/chat',                   [SupportController::class, 'chat'])->middleware('throttle:20,10');
            Route::get('/support/tickets',                 [SupportController::class, 'indexTickets']);
            Route::post('/support/tickets',                [SupportController::class, 'storeTicket']);
            Route::get('/support/tickets/{ticket}',        [SupportController::class, 'showTicket']);
            Route::post('/support/tickets/{ticket}/reply', [SupportController::class, 'replyTicket']);
            Route::get('/support/tickets/{ticket}/messages/{message}/attachment', [SupportController::class, 'messageAttachment']);

            // Notifications
            Route::prefix('notifications')->group(function () {
                Route::get('/',           [NotificationController::class, 'index']);
                Route::get('/unread',     [NotificationController::class, 'unread']);
                Route::post('/read',      [NotificationController::class, 'markAllRead']);
                Route::post('/{id}/read', [NotificationController::class, 'markRead']);
            });

            // ─────────────────────────────────────────────────────────────────
            // ADMIN
            // ─────────────────────────────────────────────────────────────────
            Route::middleware('admin')->prefix('admin')->group(function () {

                // ── Lands ─────────────────────────────────────────────────────
                Route::get('/lands',                       [LandController::class, 'adminIndex']);
                Route::post('/lands',                      [LandController::class, 'store']);
                Route::get('/lands/{land}',                [LandController::class, 'show']);
                Route::post('/lands/{land}',               [LandController::class, 'update']);
                Route::patch('/lands/{land}/price',        [LandController::class, 'updatePrice']);
                Route::patch('/lands/{land}/availability', [LandController::class, 'toggleAvailability']);
                Route::get('/lands/{land}/valuation',                        [LandController::class, 'getValuations']);
                Route::post('/lands/{land}/valuation',                       [LandController::class, 'addValuationEntry']);
                Route::patch('/lands/{land}/valuation/{year}/{month}',       [LandController::class, 'updateValuationEntry']);
                Route::delete('/lands/{land}/valuation/{year}/{month}',      [LandController::class, 'deleteValuationEntry']);

                // ── KYC ───────────────────────────────────────────────────────
                Route::get('/kyc',                    [KycController::class, 'adminIndex']);
                Route::get('/kyc/{id}',               [KycController::class, 'adminShow']);
                Route::post('/kyc/{id}/approve',      [KycController::class, 'adminApprove']);
                Route::post('/kyc/{id}/reject',       [KycController::class, 'adminReject']);
                Route::post('/kyc/{id}/resubmit',     [KycController::class, 'adminRequestResubmit']);

                // ── Referrals ─────────────────────────────────────────────────
                Route::get('/referrals',       [ReferralController::class, 'adminIndex']);
                Route::get('/referrals/stats', [ReferralController::class, 'adminStats']);

                // ── Withdrawals ───────────────────────────────────────────────
                Route::post('/withdrawals/retry', [WithdrawalController::class, 'retryPendingWithdrawals']);

                // ── Users ─────────────────────────────────────────────────────
                Route::get('/users',                       [AdminUserController::class, 'index']);
                Route::get('/users/{user}',                [AdminUserController::class, 'show']);
                Route::patch('/users/{user}/suspend',      [AdminUserController::class, 'suspend']);
                Route::patch('/users/{user}/unsuspend',    [AdminUserController::class, 'unsuspend']);
                Route::patch('/users/{user}/make-admin',   [AdminUserController::class, 'makeAdmin']);
                Route::patch('/users/{user}/remove-admin', [AdminUserController::class, 'removeAdmin']);
                Route::delete('/users/{user}',             [AdminUserController::class, 'destroy']);

                // ── Support tickets ───────────────────────────────────────────
                Route::prefix('support/tickets')->group(function () {
                    Route::get('/',                     [AdminSupportController::class, 'index']);
                    Route::get('/{ticket}',             [AdminSupportController::class, 'show']);
                    Route::post('/{ticket}/reply',      [AdminSupportController::class, 'reply']);
                    Route::patch('/{ticket}/status',    [AdminSupportController::class, 'updateStatus']);
                    Route::delete('/{ticket}',          [AdminSupportController::class, 'destroy']);
                    Route::get('/{message}/attachment', [AdminSupportController::class, 'attachment']);
                });

                //Certificates
                Route::prefix('certificates')->group(function () {
                    Route::get('/',                          [CertificateController::class, 'adminIndex']);
                    Route::post('/{certificate}/revoke',     [CertificateController::class, 'revoke']);
                    Route::post('/{certificate}/regenerate', [CertificateController::class, 'regenerate']);
                });                
            });
        });
    });