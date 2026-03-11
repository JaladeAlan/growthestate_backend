<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicket extends Model
{
    protected $fillable = [
        'user_id',
        'guest_name',   
        'guest_email',  
        'reference',
        'subject',
        'category',
        'status',
        'priority',
    ];

    public function messages()
    {
        return $this->hasMany(SupportMessage::class, 'ticket_id')->orderBy('created_at');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function latestMessage()
    {
        return $this->hasOne(SupportMessage::class, 'ticket_id')->latestOfMany();
    }
}