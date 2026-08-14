<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\Support\TestInstanceKey;
use Tests\Traits\ClientFactory;

abstract class TestCase extends BaseTestCase
{
    use ClientFactory, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('python-proxy.client_private_key', TestInstanceKey::PRIVATE);
        Config::set('python-proxy.admin_private_key', '');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Mockery::close();
    }
}
