<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceVisit extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'station_id',
        'user_id',
        'title',
        'job_reference',
        'status',
        'started_at',
        'ended_at',
        'timezone',
        'source_update_id',
        'summary',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function station()
    {
        return $this->belongsTo(Station::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function notes()
    {
        return $this->hasMany(FieldNote::class);
    }

    public function measurements()
    {
        return $this->hasMany(FieldMeasurement::class);
    }

    public function attachments()
    {
        return $this->hasMany(Attachment::class);
    }

    public function tasks()
    {
        return $this->hasMany(Task::class);
    }
}
