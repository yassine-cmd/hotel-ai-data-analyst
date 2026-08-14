<?php

namespace App\Models;

use App\Support\ClientCredentialCipher;
use App\Support\Dsn;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    protected $fillable = [
        'name',
        'public_key',
        'analytics_db_dsn',
        'analytics_agent_user',
        'analytics_agent_password',
        'analytics_admin_user',
        'analytics_admin_password',
        'agent_style',
        'is_active',
        'budget_limit_usd',
        'deactivated_at',
    ];

    protected function casts(): array
    {
        return [
            'agent_style' => 'array',
            'is_active' => 'boolean',
            'budget_limit_usd' => 'float',
            'deactivated_at' => 'datetime',
        ];
    }

    public function getDecryptedAgentPasswordAttribute(): string
    {
        return ClientCredentialCipher::decrypt((string) $this->analytics_agent_password);
    }

    public function getDecryptedPasswordAttribute(): string
    {
        return $this->decrypted_agent_password;
    }

    public function getDecryptedAdminPasswordAttribute(): string
    {
        return ClientCredentialCipher::decrypt((string) $this->analytics_admin_password);
    }

    public function getAnalyticsDbUserAttribute(): string
    {
        return $this->analytics_agent_user;
    }

    public function getAnalyticsDbHostAttribute(): string
    {
        return $this->dsnParts()['host'];
    }

    public function getAnalyticsDbPortAttribute(): int
    {
        return $this->dsnParts()['port'];
    }

    public function getAnalyticsDbNameAttribute(): string
    {
        return $this->dsnParts()['database'];
    }

    private function dsnParts(): array
    {
        return Dsn::parse($this->analytics_db_dsn, ['host' => 'localhost', 'port' => 3306, 'database' => 'hotel']);
    }

    public function users()
    {
        return $this->hasMany(User::class, 'client_id');
    }
}
