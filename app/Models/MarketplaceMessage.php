<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketplaceMessage extends Model
{
    protected $fillable = [
        'listing_id', 'sender_id', 'receiver_id', 'body', 'is_read',
    ];

    protected $casts = ['is_read' => 'boolean'];

    public function sender()  {
         return $this->belongsTo(User::class,               
         'sender_id');
        }

    public function receiver() {
         return $this->belongsTo(User::class,
                'receiver_id'); 
            }

    public function listing()  { 
        return $this->belongsTo(MarketplaceListing::class, 
        'listing_id'); 
         }
}