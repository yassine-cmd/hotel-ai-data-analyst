<?php

namespace Tests\Unit\Services;

use App\Models\PermissionToken;
use App\Models\User;
use App\Services\UserAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class UserAccessServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): UserAccessService
    {
        return new UserAccessService();
    }

    private function token(string $code, array $tables): void
    {
        PermissionToken::create([
            'code' => $code,
            'name' => $code,
            'grants' => ['tables' => $tables],
            'is_active' => true,
        ]);
        Cache::forget('permission_token_grants');
    }

    private function user(array $permissions): User
    {
        return new User(['permissions' => $permissions]);
    }

    public function test_admin_has_null_allowed_columns(): void
    {
        $this->token('RECEPTION', ['caisse' => '*']);
        $user = $this->user(['role' => 1, 'permissions' => ['RECEPTION']]);

        $this->assertNull($this->service()->allowedColumns($user));
    }

    public function test_star_tables_are_unrestricted(): void
    {
        $this->token('RECEPTION', ['caisse' => '*', 'facture' => '*']);
        $user = $this->user(['role' => 0, 'permissions' => ['RECEPTION']]);

        $this->assertSame([], $this->service()->allowedColumns($user));
    }

    public function test_explicit_columns_are_returned(): void
    {
        $this->token('RECEPTION', ['caisse' => ['columns' => ['id', 'total']]]);
        $user = $this->user(['role' => 0, 'permissions' => ['RECEPTION']]);

        $this->assertSame(['caisse' => ['id', 'total']], $this->service()->allowedColumns($user));
    }

    public function test_star_beats_explicit_for_same_table(): void
    {
        $this->token('A', ['caisse' => '*']);
        $this->token('B', ['caisse' => ['columns' => ['id']]]);
        $user = $this->user(['role' => 0, 'permissions' => ['A', 'B']]);

        $this->assertSame([], $this->service()->allowedColumns($user));
    }

    public function test_union_across_tokens(): void
    {
        $this->token('A', ['caisse' => ['columns' => ['id']]]);
        $this->token('B', ['caisse' => ['columns' => ['total']]]);
        $user = $this->user(['role' => 0, 'permissions' => ['A', 'B']]);

        $this->assertSame(['caisse' => ['id', 'total']], $this->service()->allowedColumns($user));
    }

    public function test_missing_token_is_ignored(): void
    {
        $this->token('A', ['caisse' => ['columns' => ['id']]]);
        $user = $this->user(['role' => 0, 'permissions' => ['UNKNOWN']]);

        $this->assertSame([], $this->service()->allowedColumns($user));
    }

    public function test_pos_token_excludes_ca_columns(): void
    {
        // Mirrors what the CA-column migration/seeder writes for RESTAURATION_I:
        // the "total" (CA) column is withheld from the allow-list.
        $this->token('RESTAURATION_I', [
            'caisse_entree' => ['columns' => ['id', 'date']],
        ]);
        $user = $this->user(['role' => 0, 'permissions' => ['RESTAURATION_I']]);

        $this->assertSame(
            ['caisse_entree' => ['id', 'date']],
            $this->service()->allowedColumns($user)
        );
    }

    public function test_caisse_star_defeats_pos_restriction(): void
    {
        // A bar/restaurant user also holds CAISSE ("*"), whose union restores
        // unrestricted access to the table — a documented PMS-vs-agent gap.
        $this->token('RESTAURATION_I', [
            'caisse_entree' => ['columns' => ['id', 'date']],
        ]);
        $this->token('CAISSE', ['caisse_entree' => '*']);
        $user = $this->user(['role' => 0, 'permissions' => ['RESTAURATION_I', 'CAISSE']]);

        $this->assertSame([], $this->service()->allowedColumns($user));
    }
}
