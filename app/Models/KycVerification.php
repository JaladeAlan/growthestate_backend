<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KycVerification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'full_name',
        'date_of_birth',
        'phone_number',
        'address',
        'city',
        'state',
        'country',
        'id_type',
        'id_number',
        'id_front_path',
        'id_back_path',
        'selfie_path',
        'status',
        'rejection_reason',
        'verified_at',
        'verified_by',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'verified_at'   => 'datetime',
    ];

    protected $hidden = [
        'id_front_path',
        'id_back_path',
        'selfie_path',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }
}