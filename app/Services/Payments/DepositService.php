<?php

namespace App\Services\Payments;

use App\Models\Deposit;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DepositService
{
    public const FEE_PERCENT  = 2;

    public const FEE_CAP_KOBO = 300_000;

    public const MIN_KOBO = 100_000; // ₦1,000

    /**
     * Create a deposit record.
     *
     * @param  User    $user
     * @param  int     $amountKobo  Amount already in kobo (e.g. 100000 = ₦1,000)
     * @param  string  $gateway
     * @return Deposit
     */
    public static function createDepositKobo(User $user, int $amountKobo, string $gateway): Deposit
    {
        if ($amountKobo < self::MIN_KOBO) {
            throw new \InvalidArgumentException(
                sprintf('Deposit amount must be at least ₦%s.', number_format(self::MIN_KOBO / 100))
            );
        }

        $feeKobo   = (int) min(round($amountKobo * (self::FEE_PERCENT / 100)), self::FEE_CAP_KOBO);
        $totalKobo = $amountKobo + $feeKobo;

        $deposit = Deposit::create([
            'user_id'         => $user->id,
            'reference'       => 'DEP-' . Str::uuid(),
            'amount_kobo'     => $amountKobo,
            'gateway'         => $gateway,
            'transaction_fee' => $feeKobo,
            'total_kobo'      => $totalKobo,
            'status'          => 'pending',
        ]);

        Log::info('Deposit record created', [
            'user_id'    => $user->id,
            'reference'  => $deposit->reference,
            'gateway'    => $gateway,
            'amount'     => $amountKobo,
            'fee'        => $feeKobo,
            'fee_capped' => $feeKobo === self::FEE_CAP_KOBO,
            'total'      => $totalKobo,
        ]);

        return $deposit;
    }

    /**
     * Convenience wrapper that accepts naira and converts to kobo.
     * Prefer createDepositKobo() for all new call sites.
     *
     * @deprecated Use createDepositKobo() directly.
     */
    public static function createDeposit(User $user, int $amountNaira, string $gateway): Deposit
    {
        return self::createDepositKobo($user, $amountNaira * 100, $gateway);
    }

    public static function calculateFeeKobo(int $amountKobo): int
    {
        return (int) min(round($amountKobo * (self::FEE_PERCENT / 100)), self::FEE_CAP_KOBO);
    }
}