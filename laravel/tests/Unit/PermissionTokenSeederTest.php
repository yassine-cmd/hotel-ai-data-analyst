<?php

namespace Tests\Unit;

use App\Models\PermissionToken;
use App\Models\SchemaMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PermissionTokenSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedMetadata(array $tableColumns): void
    {
        foreach ($tableColumns as $table => $cols) {
            foreach ($cols as $c) {
                SchemaMetadata::create([
                    'table_name' => $table,
                    'column_name' => $c,
                    'metadata_type' => 'column',
                ]);
            }
        }
    }

    public function test_pos_tokens_exclude_ca_columns_when_metadata_present(): void
    {
        $this->seedMetadata([
            'caisse_entree' => ['id', 'date', 'total'],
            'paiement' => ['id', 'total', 'mode'],
            'facture_charge' => ['id', 'prix', 'montant'],
        ]);

        Artisan::call('db:seed', ['--class' => 'PermissionTokenSeeder']);

        $token = PermissionToken::where('code', 'RESTAURATION_I')->first();
        $tables = $token->grants['tables'];

        // CA money columns withheld; operational columns kept (order-independent).
        $this->assertEqualsCanonicalizing(['id', 'date'], $tables['caisse_entree']['columns']);
        $this->assertEqualsCanonicalizing(['id', 'mode'], $tables['paiement']['columns']);
        $this->assertEqualsCanonicalizing(['id', 'montant'], $tables['facture_charge']['columns']);

        // Non-CA tables on the same token stay fully open.
        $this->assertSame('*', $tables['produit']);
    }

    public function test_reception_keeps_ca_columns(): void
    {
        $this->seedMetadata(['caisse_entree' => ['id', 'date', 'total']]);

        Artisan::call('db:seed', ['--class' => 'PermissionTokenSeeder']);

        $reception = PermissionToken::where('code', 'RECEPTION')->first();
        // RECEPTION is not a restricted token, so its caisse tables stay "*".
        $this->assertSame('*', $reception->grants['tables']['caisse_entree']);
    }

    public function test_missing_metadata_keeps_star(): void
    {
        // No schema_metadata rows: restriction is skipped, access not broken.
        Artisan::call('db:seed', ['--class' => 'PermissionTokenSeeder']);

        $token = PermissionToken::where('code', 'RESTAURATION_I')->first();
        $this->assertSame('*', $token->grants['tables']['caisse_entree']);
    }
}
