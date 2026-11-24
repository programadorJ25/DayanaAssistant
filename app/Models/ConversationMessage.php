<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConversationMessage extends Model
{
    protected $fillable = [
        'user_id',
        'role',
        'content',
        'telegram_update_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
