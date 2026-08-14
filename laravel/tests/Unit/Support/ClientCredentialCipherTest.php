<?php

namespace Tests\Unit\Support;

use App\Support\ClientCredentialCipher;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class ClientCredentialCipherTest extends TestCase
{
    public function test_round_trip(): void
    {
        $ciphertext = ClientCredentialCipher::encrypt('secret-db-pass');

        $this->assertNotSame('secret-db-pass', $ciphertext);
        $this->assertSame('secret-db-pass', ClientCredentialCipher::decrypt($ciphertext));
    }

    public function test_uses_credentials_key_not_app_key(): void
    {
        $ciphertext = ClientCredentialCipher::encrypt('secret-db-pass');

        $readableWithAppKey = false;
        try {
            Crypt::decryptString($ciphertext);
            $readableWithAppKey = true;
        } catch (\Throwable $e) {
            // expected: APP_KEY cannot open credentials-key ciphertext
        }

        $this->assertFalse(
            $readableWithAppKey,
            'ClientCredentialCipher output must not be readable with APP_KEY — proof the keys are decoupled.'
        );
    }

    public function test_swapping_credential_key_orphans_old_ciphertext(): void
    {
        // Proves the credential key, not APP_KEY, protects the data: swapping
        // CLIENT_CREDENTIALS_KEY orphans old ciphertext (which is why rotation
        // must go through `clients:rekey`, never a blind key change).
        $ciphertext = ClientCredentialCipher::encrypt('secret-db-pass');

        $old = config('credentials.key');
        config()->set('credentials.key', base64_encode(random_bytes(32)));

        $decryptedOk = false;
        try {
            ClientCredentialCipher::decrypt($ciphertext);
            $decryptedOk = true;
        } catch (\Throwable $e) {
            // expected
        } finally {
            config()->set('credentials.key', $old);
        }

        $this->assertFalse($decryptedOk, 'Old credential-key ciphertext must be unreadable after a key swap.');
    }

    public function test_missing_key_throws_clear_error(): void
    {
        $old = config('credentials.key');
        config()->set('credentials.key', '');

        try {
            ClientCredentialCipher::encrypt('x');
            $this->fail('Expected RuntimeException when CLIENT_CREDENTIALS_KEY is unset.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('CLIENT_CREDENTIALS_KEY', $e->getMessage());
        } finally {
            config()->set('credentials.key', $old);
        }
    }
}