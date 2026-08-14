<?php

use App\Models\PermissionToken;
use App\Models\SchemaMetadata;
use Illuminate\Database\Migrations\Migration;

/**
 * Per the Atlas PMS privilege model (docs/admin-guide.md), the
 * point-of-sale operational tokens must not expose chiffre d'affaires
 * (revenue) columns. We encode that as a per-column allow-list on the
 * affected tables: every column except the CA money column.
 *
 * Tables/columns not yet present in schema_metadata are left as "*" so
 * access is never broken before the hotel schema is synced; re-running the
 * PermissionTokenSeeder (or this migration) after a sync materializes them.
 *
 * This is a data-only change to permission_tokens.grants; the schema and the
 * global sensitive_rules pool are untouched.
 */
return new class extends Migration
{
    private const RESTRICTED_TOKENS = [
        'RESTAURATION_M', 'RESTAURATION_I', 'CHEF_BAR', 'SPA', 'EVENT',
    ];

    private const CA_EXCLUSIONS = [
        'facture_charge' => ['prix'],
        'caisse_entree'  => ['total'],
        'paiement'       => ['total'],
        'deposit'        => ['montant'],
    ];

    public function up(): void
    {
        foreach (self::RESTRICTED_TOKENS as $code) {
            $token = PermissionToken::where('code', $code)->first();
            if (!$token) {
                continue;
            }

            $grants = $token->grants ?? [];
            $tables = $grants['tables'] ?? [];

            foreach ($tables as $table => $spec) {
                $tbl = strtolower((string) $table);
                $excluded = self::CA_EXCLUSIONS[$tbl] ?? [];
                if (empty($excluded)) {
                    continue;
                }

                $columns = SchemaMetadata::where('table_name', $tbl)
                    ->whereNotNull('column_name')
                    ->pluck('column_name')
                    ->map(fn ($c) => strtolower((string) $c))
                    ->all();

                if (empty($columns)) {
                    continue; // no metadata yet — keep "*"
                }

                $allowed = array_values(array_diff(
                    $columns,
                    array_map('strtolower', $excluded)
                ));
                sort($allowed);

                $tables[$table] = ['columns' => $allowed];
            }

            $grants['tables'] = $tables;
            $token->grants = $grants;
            $token->save();
        }
    }

    public function down(): void
    {
        foreach (self::RESTRICTED_TOKENS as $code) {
            $token = PermissionToken::where('code', $code)->first();
            if (!$token) {
                continue;
            }

            $grants = $token->grants ?? [];
            $tables = $grants['tables'] ?? [];

            foreach ($tables as $table => $spec) {
                $tbl = strtolower((string) $table);
                if (!isset(self::CA_EXCLUSIONS[$tbl])) {
                    continue;
                }
                if (is_array($spec) && array_key_exists('columns', $spec)) {
                    $tables[$table] = '*';
                }
            }

            $grants['tables'] = $tables;
            $token->grants = $grants;
            $token->save();
        }
    }
};
