<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    protected $fillable = [
        'user_id',
        'task_id',
        'type',
        'notification_key',
        'message',
        'status',
        'sent_at',
        'attempts',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];
}
