<?php

namespace App\Services;

use App\Models\PermissionToken;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves which tables a user may read, based on the hotel PMS permission
 * tokens stored on the user row.
 *
 * role=1 (Administrateur) users get the whole schema (null = unrestricted);
 * everyone else gets the union of their tokens' table grants.
 */
class UserAccessService
{
    private const TOKEN_GRANTS_CACHE_KEY = 'permission_token_grants';
    private const TOKEN_GRANTS_TTL = 300;

    /**
     * Active permission tokens as code => grants array.
     */
    public function tokenGrants(): array
    {
        return Cache::remember(self::TOKEN_GRANTS_CACHE_KEY, self::TOKEN_GRANTS_TTL, function () {
            return PermissionToken::where('is_active', true)
                ->get(['code', 'grants'])
                ->mapWithKeys(fn ($t) => [$t->code => $t->grants])
                ->all();
        });
    }

    public function forgetTokenGrantsCache(): void
    {
        Cache::forget(self::TOKEN_GRANTS_CACHE_KEY);
    }

    public function isAdmin(User $user): bool
    {
        return (int) ($user->permissions['role'] ?? 0) === 1;
    }

    /**
     * Table names (lower-cased) this user may read, or null for "all tables"
     * (admins). Never hits the client database.
     */
    public function allowedTables(User $user): ?array
    {
        if ($this->isAdmin($user)) {
            return null;
        }

        $tokens = array_values(array_filter((array) ($user->permissions['permissions'] ?? [])));
        if (empty($tokens)) {
            return [];
        }

        $grants = $this->tokenGrants();
        $allowed = [];
        foreach ($tokens as $code) {
            $tokenTables = $grants[$code]['tables'] ?? null;
            if (!is_array($tokenTables)) {
                continue;
            }
            foreach (array_keys($tokenTables) as $granted) {
                $allowed[strtolower((string) $granted)] = true;
            }
        }

        return array_values(array_keys($allowed));
    }

    /**
     * Per-column allow-list this user may read, keyed by lower-cased table name,
     * or null for "all columns" (admins), or [] when none of the user's tokens
     * restrict columns.
     *
     * Only tables whose grant is an explicit {"columns":[...]} list appear here.
     * A table granted as "*" (or via any token that grants it as "*") is omitted
     * so the agent treats it as unrestricted on columns. The values are the
     * union of the user's tokens' column lists for that table.
     */
    public function allowedColumns(User $user): ?array
    {
        if ($this->isAdmin($user)) {
            return null;
        }

        $tokens = array_values(array_filter((array) ($user->permissions['permissions'] ?? [])));
        if (empty($tokens)) {
            return [];
        }

        $grants = $this->tokenGrants();
        $allowed = [];
        $unrestricted = [];

        foreach ($tokens as $code) {
            $tokenTables = $grants[$code]['tables'] ?? null;
            if (!is_array($tokenTables)) {
                continue;
            }

            foreach ($tokenTables as $table => $spec) {
                $tbl = strtolower((string) $table);

                // "*" (or {"columns":"*"}) means all columns for this table.
                $isStar = $spec === '*'
                    || (is_array($spec) && ($spec['columns'] ?? null) === '*');
                if ($isStar) {
                    $unrestricted[$tbl] = true;
                    unset($allowed[$tbl]);
                    continue;
                }

                if (!isset($unrestricted[$tbl])
                    && is_array($spec)
                    && isset($spec['columns'])
                    && is_array($spec['columns'])
                ) {
                    $cols = array_map(
                        fn ($c) => strtolower((string) $c),
                        $spec['columns']
                    );
                    $allowed[$tbl] = array_values(array_unique(
                        array_merge($allowed[$tbl] ?? [], $cols)
                    ));
                }
            }
        }

        return $allowed;
    }
}
