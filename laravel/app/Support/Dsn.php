<?php

namespace App\Support;

class Dsn
{
    /**
     * Parse a MySQL DSN into host/port/database.
     *
     * Accepted forms (the DB can be anywhere: localhost or a remote host):
     *   mysql://user:pass@host:3306/dbname
     *   host:3306/dbname
     *   host/dbname          (port defaults to 3306)
     *   host                 (port/database from defaults)
     */
    public static function parse(?string $dsn, array $defaults = []): array
    {
        $host = $defaults['host'] ?? '127.0.0.1';
        $port = (int) ($defaults['port'] ?? 3306);
        $database = $defaults['database'] ?? '';

        $dsn = trim((string) $dsn);
        if ($dsn === '') {
            return compact('host', 'port', 'database');
        }

        // Full URL form: mysql://user:pass@host:3306/dbname
        if (str_contains($dsn, '://')) {
            $parts = parse_url($dsn);
            $host = $parts['host'] ?? $host;
            if (isset($parts['port'])) {
                $port = (int) $parts['port'];
            }
            $database = ltrim($parts['path'] ?? '', '/') ?: $database;

            return compact('host', 'port', 'database');
        }

        // Authority form: host:3306/dbname | host/dbname | host
        $slash = strpos($dsn, '/');
        $authority = $slash === false ? $dsn : substr($dsn, 0, $slash);
        $database = $slash === false ? $database : substr($dsn, $slash + 1);

        if (str_starts_with($authority, '[')) {
            // IPv6: [::1]:3306 or [::1]
            $bracket = strpos($authority, ']');
            $host = substr($authority, 1, ($bracket === false ? strlen($authority) : $bracket) - 1);
            if ($bracket !== false && str_starts_with(substr($authority, $bracket + 1), ':')) {
                $port = (int) substr($authority, $bracket + 2) ?: 3306;
            }
        } elseif (str_contains($authority, ':')) {
            [$host, $port] = explode(':', $authority, 2);
            $port = (int) $port;
        } else {
            $host = $authority;
        }

        return compact('host', 'port', 'database');
    }
}
