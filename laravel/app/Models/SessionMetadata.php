<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SessionMetadata extends Model
{
    protected $table = 'session_metadata';
    public $timestamps = false;

    protected $fillable = [
        'session_id', 'client_id', 'user_id', 'user_name', 'name', 'turn_count',
        'total_tokens', 'prompt_tokens', 'completion_tokens', 'reasoning_tokens',
        'context_window', 'path', 'created_at', 'last_access',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'turn_count' => 'integer',
        'total_tokens' => 'integer',
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'reasoning_tokens' => 'integer',
        'context_window' => 'array',
        'created_at' => 'datetime',
        'last_access' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
