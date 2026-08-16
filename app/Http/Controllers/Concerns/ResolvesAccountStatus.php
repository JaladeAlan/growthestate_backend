<?php

namespace App\Http\Controllers\Concerns;

use App\Models\KycVerification;
use App\Models\User;

/**
 * Shared PIN/KYC status resolution logic.
 *
 * Originally lived as private methods on ProfileController (GET /me,
 * GET /user/account-status). Extracted so AuthController's login()
 * response can include the same pin_is_set/kyc_status fields as /me
 * without duplicating (and risking drift in) this logic.
 */
trait ResolvesAccountStatus
{
    protected function userHasPin(User $user): bool
    {
        return !empty($user->transaction_pin);
    }

    protected function isKycVerified(User $user): bool
    {
        return $this->resolveKycStatus($user) === 'approved';
    }

    protected function resolveKycStatus(User $user): string
    {
        // If the user model carries a direct kyc_status column, trust it.
        if (!empty($user->kyc_status)) {
            return $user->kyc_status;
        }

        // Otherwise check the kyc_verifications table for the latest record.
        $kyc = KycVerification::where('user_id', $user->id)
            ->latest()
            ->value('status');

        return $kyc ?? 'none';
    }

    /**
     * Returns a human-readable list of reasons why the user cannot transact.
     * Empty array means they are fully cleared.
     */
    protected function blockingReasons(bool $hasPin, string $kycStatus): array
    {
        $reasons = [];

        if (!$hasPin) {
            $reasons[] = 'Transaction PIN not set.';
        }

        match ($kycStatus) {
            'none'     => $reasons[] = 'KYC verification not submitted.',
            'pending'  => $reasons[] = 'KYC verification is under review.',
            'rejected' => $reasons[] = 'KYC verification was rejected. Please resubmit.',
            'resubmit' => $reasons[] = 'KYC resubmission required.',
            default    => null,
        };

        return $reasons;
    }
}