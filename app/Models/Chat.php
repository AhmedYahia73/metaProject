<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Chat extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'message',
        'is_image',
        'is_admin',
    ];
}
