<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiUsageLog extends Model
{
    protected $fillable = [
        'request_uuid',
        'provider',
        'model',
        'operation',
        'response_id',
        'input_tokens',
        'cached_tokens',
        'output_tokens',
        'reasoning_tokens',
        'total_tokens',
        'estimated_cost_usd',
        'reserved_cost_usd',
        'status',
        'metadata',
    ];

    protected $casts = [
        'input_tokens' => 'integer',
        'cached_tokens' => 'integer',
        'output_tokens' => 'integer',
        'reasoning_tokens' => 'integer',
        'total_tokens' => 'integer',
        'estimated_cost_usd' => 'decimal:9',
        'reserved_cost_usd' => 'decimal:9',
        'metadata' => 'array',
    ];
}
