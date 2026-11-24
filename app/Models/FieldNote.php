<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FieldNote extends Model
{
    protected $fillable = [
        'service_visit_id',
        'user_id',
        'type',
        'equipment',
        'content',
        'original_text',
        'recorded_at',
        'source_update_id',
        'source_item_index',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
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
