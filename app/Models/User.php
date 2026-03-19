<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject, MustVerifyEmail
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'uid',
        'name',
        'email',
        'password',
        'is_admin',
        'is_suspended',
        'email_verified_at',
        'transaction_pin',
        'pin_reset_code',
        'pin_reset_expires_at',        
        'pin_reset_token',             
        'pin_reset_token_expires_at',
        'verification_code',
        'verification_code_expiry',
        'password_reset_code',
        'password_reset_code_expires_at',
        'password_reset_verified',
        'balance_kobo',
        'rewards_balance_kobo',
        'bank_name',
        'bank_code',
        'account_number',
        'account_name',
        'recipient_code',
        'referral_code',
        'referred_by',
        'bank_verified',
        'last_transaction_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'transaction_pin',
        'pin_reset_code',
        'verification_code',
        'password_reset_code',
    ];

    protected $casts = [
        'email_verified_at'              => 'datetime',
        'password'                       => 'hashed',
        'verification_code_expiry'       => 'datetime',
        'password_reset_code_expires_at' => 'datetime',
        'pin_reset_expires_at'           => 'datetime',  
        'pin_reset_token_expires_at'     => 'datetime', 
        'balance_kobo'                   => 'integer',
        'rewards_balance_kobo'           => 'integer',
        'referred_by'                    => 'integer',
        'is_admin'                       => 'boolean',
        'is_suspended'                   => 'boolean',
        'bank_verified'                  => 'boolean',
    ];

    protected static function booted()
    {
        static::creating(function ($user) {
            // Use UUID format to match existing DB records
            $user->uid = (string) Str::uuid();
        });

        static::created(function (User $user) {
            if (! $user->referral_code) {
                $maxAttempts = 10;
                $attempts    = 0;

                do {
                    $code     = strtoupper(substr(md5(uniqid()), 0, 8));
                    $attempts++;

                    if ($attempts >= $maxAttempts) {
                        throw new \RuntimeException(
                            'Unable to generate a unique referral code after ' . $maxAttempts . ' attempts.'
                        );
                    }
                } while (User::where('referral_code', $code)->exists());

                $user->update(['referral_code' => $code]);
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | JWT
    |--------------------------------------------------------------------------
    */

    public function getJWTIdentifier()
    {
        return $this->uid;
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function ledgerEntries()
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function purchases()
    {
        return $this->hasMany(Purchase::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function lands()
    {
        return $this->belongsToMany(Land::class, 'user_land')
            ->withPivot('units')
            ->withTimestamps();
    }

    public function userLands()
    {
        return $this->hasMany(UserLand::class);
    }

    public function portfolioSnapshots()
    {
        return $this->hasMany(PortfolioDailySnapshot::class);
    }

    public function portfolioLandSnapshots()
    {
        return $this->hasMany(PortfolioLandSnapshot::class);
    }

    public function portfolioAssetSnapshots()
    {
        return $this->hasMany(PortfolioAssetSnapshot::class);
    }

    public function latestPortfolioSnapshot()
    {
        return $this->hasOne(PortfolioDailySnapshot::class)
            ->latestOfMany('snapshot_date');
    }

    public function kycVerification()
    {
        return $this->hasOne(KycVerification::class);
    }

    public function referredBy()
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    public function referrals()
    {
        return $this->hasMany(Referral::class, 'referrer_id');
    }

    public function referredUsers()
    {
        return $this->belongsToMany(User::class, 'referrals', 'referrer_id', 'referred_user_id');
    }

    public function referralRewards()
    {
        return $this->hasMany(ReferralReward::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Main Wallet Helpers
    |--------------------------------------------------------------------------
    */

    public function deposit(int $amountKobo, string $reference = null): void
    {
        $this->increment('balance_kobo', $amountKobo);
        $balanceAfter = $this->fresh()->balance_kobo;

        LedgerEntry::create([
            'uid'           => $this->id,
            'type'          => 'deposit',
            'amount_kobo'   => $amountKobo,
            'balance_after' => $balanceAfter,
            'reference'     => $reference ?? 'DEP-' . now()->timestamp,
        ]);
    }

    public function withdraw(int $amountKobo, string $reference = null): bool
    {
        if ($this->balance_kobo < $amountKobo) {
            return false;
        }

        $this->decrement('balance_kobo', $amountKobo);
        $balanceAfter = $this->fresh()->balance_kobo;

        LedgerEntry::create([
            'uid'           => $this->id,
            'type'          => 'withdrawal',
            'amount_kobo'   => $amountKobo,
            'balance_after' => $balanceAfter,
            'reference'     => $reference ?? 'WDL-' . now()->timestamp,
        ]);

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Rewards Wallet Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Credit the rewards wallet.
     * Called when a reward is earned (referral, cashback, promo).
     */
    public function creditRewards(int $amountKobo, string $reference, string $note = ''): void
    {
        $this->increment('rewards_balance_kobo', $amountKobo);
        $rewardsAfter = $this->fresh()->rewards_balance_kobo;

        LedgerEntry::create([
            'uid'                   => $this->id,
            'type'                  => 'reward_credit',
            'amount_kobo'           => $amountKobo,
            'balance_after'         => $this->fresh()->balance_kobo,
            'rewards_balance_after' => $rewardsAfter,
            'reference'             => $reference,
            'note'                  => $note ?: 'Reward credit',
        ]);
    }

    /**
     * Spend from rewards wallet (e.g. applied to a purchase).
     * Returns false if insufficient rewards balance.
     */
    public function spendRewards(int $amountKobo, string $reference, string $note = ''): bool
    {
        if ($this->rewards_balance_kobo < $amountKobo) {
            return false;
        }

        $this->decrement('rewards_balance_kobo', $amountKobo);
        $rewardsAfter = $this->fresh()->rewards_balance_kobo;

        LedgerEntry::create([
            'uid'                   => $this->id,
            'type'                  => 'reward_spend',
            'amount_kobo'           => $amountKobo,
            'balance_after'         => $this->fresh()->balance_kobo,
            'rewards_balance_after' => $rewardsAfter,
            'reference'             => $reference,
            'note'                  => $note ?: 'Reward spend',
        ]);

        return true;
    }

    /**
     * Total spendable balance = main wallet + rewards wallet.
     */
    public function getTotalSpendableKoboAttribute(): int
    {
        return $this->balance_kobo + $this->rewards_balance_kobo;
    }

    /*
    |--------------------------------------------------------------------------
    | Computed Attributes
    |--------------------------------------------------------------------------
    */

    public function getIsKycVerifiedAttribute(): bool
    {
        return $this->kycVerification?->status === 'approved';
    }

    public function getKycStatusAttribute(): string
    {
        return $this->kycVerification?->status ?? 'not_submitted';
    }
}