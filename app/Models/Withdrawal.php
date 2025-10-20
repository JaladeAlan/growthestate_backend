<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Withdrawal extends Model
{
    use HasFactory;

    // Mass assignable attributes
    protected $fillable = [
        'user_id',
        'amount',
        'status',
        'reference',
    ];

    // Status Constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    // Define relationship with User
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Check if withdrawal is pending
    public function isPending()
    {
        return $this->status === self::STATUS_PENDING;
    }

    // Check if withdrawal is completed
    public function isCompleted()
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    // Check if withdrawal failed
    public function isFailed()
    {
        return $this->status === self::STATUS_FAILED;
    }
}
