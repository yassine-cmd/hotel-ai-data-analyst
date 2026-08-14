<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PermissionToken extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'grants',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'grants' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
