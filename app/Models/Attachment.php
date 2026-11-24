<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attachment extends Model
{
    protected $fillable = [
        'service_visit_id',
        'user_id',
        'type',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'caption',
        'telegram_file_id',
        'telegram_file_unique_id',
        'source_update_id',
    ];

    public function visit()
    {
        return $this->belongsTo(
            ServiceVisit::class,
            'service_visit_id'
        );
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
