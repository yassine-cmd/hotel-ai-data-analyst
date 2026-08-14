<?php

return [
    // Encryption key for tenant analytics-DB credentials at rest. Deliberately
    // separate from APP_KEY: a routine `php artisan key:generate` (or a fresh
    // .env) must never orphan the stored client DB passwords.
    //
    // Generate once per deployment with: php artisan clients:key:generate
    // Rotate ONLY via:                        php artisan clients:rekey
    // Runbook:  docs/admin-guide.md
    'key' => env('CLIENT_CREDENTIALS_KEY', ''),
];