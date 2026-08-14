<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchemaMetadata extends Model
{
    protected $table = 'schema_metadata';

    protected $fillable = [
        'metadata_type',
        'table_name',
        'column_name',
        'row_count',
        'ordinal_position',
        'data_type',
        'column_type',
        'column_key',
        'is_nullable',
        'enum_values',
        'foreign_keys',
        'description',
        'description_source',
        'value_mappings',
        'value_mappings_source',
        'is_sensitive',
        'sensitivity_source',
        'virtual_foreign_keys',
        'virtual_foreign_keys_source',
        'is_archived',
        'archived_at',
        'row_version',
    ];

    protected function casts(): array
    {
        return [
            'value_mappings' => 'array',
            'virtual_foreign_keys' => 'array',
            'foreign_keys' => 'array',
            'enum_values' => 'array',
            'is_sensitive' => 'boolean',
            'is_nullable' => 'boolean',
            'is_archived' => 'boolean',
            'row_count' => 'integer',
            'ordinal_position' => 'integer',
            'row_version' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_archived', false);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('metadata_type', $type);
    }

    public function scopeByTable($query, string $tableName)
    {
        return $query->where('table_name', $tableName);
    }

    public function isTableLevel(): bool
    {
        return $this->metadata_type === 'table';
    }

    public function isColumnLevel(): bool
    {
        return $this->metadata_type === 'column';
    }
}
