<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TokenUsage extends Model
{
    protected $table = 'token_usage';

    protected $fillable = [
        'session_id',
        'client_id',
        'user_id',
        'user_name',
        'turn_uuid',
        'prompt_tokens',
        'cache_hit_tokens',
        'cache_miss_tokens',
        'completion_tokens',
        'reasoning_tokens',
        'total_tokens',
        'cost_usd',
        'created_at',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'prompt_tokens' => 'integer',
            'cache_hit_tokens' => 'integer',
            'cache_miss_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'total_tokens' => 'integer',
            'cost_usd' => 'float',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
