<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Faq extends Model {
    protected $fillable = ['category','question','answer','is_active','sort_order'];
}
