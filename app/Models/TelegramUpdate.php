<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramUpdate extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'update_id',
        'telegram_user_id',
        'source',
        'payload',
        'status',
        'attempts',
        'processed_at',
        'last_error',
    ];

    protected $casts = [
        'update_id' => 'integer',
        'payload' => 'array',
        'attempts' => 'integer',
        'processed_at' => 'datetime',
    ];
}
