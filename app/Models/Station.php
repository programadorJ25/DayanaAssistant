<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Station extends Model
{
    protected $fillable = [
        'workspace_id',
        'name',
        'client_name',
        'city',
        'country',
        'description',
        'external_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function visits()
    {
        return $this->hasMany(ServiceVisit::class);
    }
}
