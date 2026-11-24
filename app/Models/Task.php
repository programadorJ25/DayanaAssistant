<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Task extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_WAITING = 'waiting_response';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_MEDIUM = 'medium';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';

    protected $fillable = [
        'workspace_id',
        'project_id',
        'user_id',
        'title',
        'description',
        'status',
        'priority',
        'due_at',
        'remind_at',
        'follow_up_at',
        'completed_at',
        'reminder_sent_at',
        'source',
        'source_update_id',
        'source_item_index',
        'service_visit_id',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'remind_at' => 'datetime',
        'follow_up_at' => 'datetime',
        'completed_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function histories()
    {
        return $this->hasMany(TaskHistory::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
        ]);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()
            ->whereNotNull('due_at')
            ->where('due_at', '<', now());
    }

    public function scopeForUser(
        Builder $query,
        int $userId
    ): Builder {
        return $query->where('user_id', $userId);
    }

    public function serviceVisit()
    {
        return $this->belongsTo(ServiceVisit::class);
    }
}
