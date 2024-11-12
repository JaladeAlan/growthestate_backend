<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Support\Str;
use Carbon\Carbon;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'balance',
        'bank_account_number',
        'bank_code',
        'uid',
        'verification_code',
        'verification_code_expiry',
        'password_reset_code',
        'password_reset_code_expires_at'
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'bank_account_number',
        'bank_code',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'verification_code_expiry' => 'datetime',
        'password_reset_code_expires_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::creating(function ($user) {
            do {
                $user->uid = 'USR-' . strtoupper(Str::random(6));
            } while (self::where('uid', $user->uid)->exists());
        });
    }

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    public function purchases()
    {
        return $this->hasMany(Purchase::class);
    }

    public function lands()
    {
        return $this->belongsToMany(Land::class, 'user_land')
                    ->withPivot('units')
                    ->withTimestamps();
    }

    public function sendEmailVerificationCode()
    {
        $this->verification_code = Str::random(6);
        $this->verification_code_expiry = now()->addMinutes(30);
        $this->save();

        try {
            \Mail::to($this->email)->send(new \App\Mail\VerifyEmailMail($this->verification_code));
        } catch (\Exception $e) {
            // Handle the failure case if needed
            \Log::error("Failed to send verification email: " . $e->getMessage());
        }
    }

    public function verifyEmail($code)
    {
        if ($this->verification_code === $code && now()->lessThanOrEqualTo($this->verification_code_expiry)) {
            $this->email_verified_at = now();
            $this->verification_code = null;
            $this->verification_code_expiry = null;
            $this->save();
            return true;
        }

        return false;
    }

    public function deposit($amount)
    {
        $this->balance += $amount;
        $this->save();
    }

    public function withdraw($amount)
    {
        if ($this->balance >= $amount) {
            $this->balance -= $amount;
            $this->save();
            return true;
        }

        return false;
    }
}
