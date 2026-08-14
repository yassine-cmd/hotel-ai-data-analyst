<?php

namespace Tests\Unit\Support;

use App\Support\Dsn;
use Tests\TestCase;

class DsnTest extends TestCase
{
    public function test_full_url_format(): void
    {
        $parsed = Dsn::parse('mysql://root:secret@db.example.com:3307/analytics');

        $this->assertSame('db.example.com', $parsed['host']);
        $this->assertSame(3307, $parsed['port']);
        $this->assertSame('analytics', $parsed['database']);
    }

    public function test_host_port_db_format(): void
    {
        $parsed = Dsn::parse('localhost:3306/hotel');

        $this->assertSame('localhost', $parsed['host']);
        $this->assertSame(3306, $parsed['port']);
        $this->assertSame('hotel', $parsed['database']);
    }

    public function test_host_db_without_port_defaults_to_3306(): void
    {
        $parsed = Dsn::parse('192.168.1.10/analytics');

        $this->assertSame('192.168.1.10', $parsed['host']);
        $this->assertSame(3306, $parsed['port']);
        $this->assertSame('analytics', $parsed['database']);
    }

    public function test_bare_host_uses_defaults(): void
    {
        $parsed = Dsn::parse('db.example.com', ['database' => 'hotel']);

        $this->assertSame('db.example.com', $parsed['host']);
        $this->assertSame(3306, $parsed['port']);
        $this->assertSame('hotel', $parsed['database']);
    }

    public function test_empty_dsn_uses_defaults(): void
    {
        $parsed = Dsn::parse(null, ['host' => 'localhost', 'port' => 3306, 'database' => 'hotel']);

        $this->assertSame('localhost', $parsed['host']);
        $this->assertSame(3306, $parsed['port']);
        $this->assertSame('hotel', $parsed['database']);
    }

    public function test_ipv6_host(): void
    {
        $parsed = Dsn::parse('[::1]:3307/analytics');

        $this->assertSame('::1', $parsed['host']);
        $this->assertSame(3307, $parsed['port']);
        $this->assertSame('analytics', $parsed['database']);
    }
}
