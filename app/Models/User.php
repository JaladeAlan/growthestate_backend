<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject, MustVerifyEmail
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'uid',
        'name',
        'email',
        'password',
        'is_admin',
        'is_suspended',
        'suspended_by_compliance',
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
        'screening_status',
        'last_screened_at',
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
        'suspended_by_compliance'        => 'boolean',
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

    public function recentTransactions(): HasMany
    {
        return $this->hasMany(Transaction::class)->latest()->limit(10);
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

    /*
    |--------------------------------------------------------------------------
    | RBAC
    |--------------------------------------------------------------------------
    */

    public function roles(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(\App\Models\Role::class, 'role_user')
            ->withPivot(['assigned_at', 'assigned_by']);
    }

    /**
     * is_admin is kept as a fast, coarse "is this a staff account" flag
     * (used e.g. by AdminMiddleware to gate the whole /admin prefix) and
     * always implies every permission, independent of assigned roles —
     * this preserves behavior for any account that predates RBAC or is
     * granted admin outside the role system.
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->is_admin) {
            return true;
        }

        return \Illuminate\Support\Facades\Cache::remember(
            "user:{$this->id}:permission:{$permission}",
            300,
            fn () => $this->roles()
                ->whereHas('permissions', fn ($q) => $q->where('name', $permission))
                ->exists()
        );
    }

    public function hasRole(string $role): bool
    {
        if ($this->is_admin) {
            return true;
        }

        return $this->roles()->where('name', $role)->exists();
    }
}