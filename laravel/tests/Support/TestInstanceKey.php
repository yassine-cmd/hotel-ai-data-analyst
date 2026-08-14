<?php

namespace Tests\Support;

/**
 * Fixed Ed25519 keypair used to make the test instance resolve to a tenant.
 * The matching public key is registered on test clients (see ClientFactory),
 * so the instance is strictly tenant-scoped exactly as in production. Not a
 * real secret — test-only fixture.
 */
final class TestInstanceKey
{
    public const PRIVATE = 'c8e9ecd80b9928c3894a32be978b460bf67961ae6ba3732c33c3d787141bb9a6';
    public const PUBLIC = '0e204924fea70ce4266df90bde12f51564f6a442de13feb958b2ffae9093c5b4';
}
