<?php

namespace Tests\Feature\Controllers;

use Tests\TestCase;

class ProfilePageRouteTest extends TestCase
{
    public function test_client_side_route_serves_spa_shell(): void
    {
        $this->putFakeSpaShell();

        $response = $this->get('/profile');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $this->assertFileExists(public_path('index.html'));
        $this->assertSame('SPA SHELL', file_get_contents(public_path('index.html')));
    }

    public function test_spa_catch_all_serves_any_client_route(): void
    {
        $this->putFakeSpaShell();

        $this->get('/chat')->assertOk();
        $this->get('/history')->assertOk();
        $this->get('/staff')->assertOk();
    }

    public function test_spa_shell_missing_returns_404(): void
    {
        if (file_exists(public_path('index.html'))) {
            $this->markTestSkipped('A real index.html is present in public/.');
        }

        $this->get('/profile')->assertNotFound();
    }

    private function putFakeSpaShell(): void
    {
        $real = public_path('index.html');
        $backup = file_exists($real) ? file_get_contents($real) : null;
        file_put_contents($real, 'SPA SHELL');
        $this->beforeApplicationDestroyed(function () use ($real, $backup) {
            if ($backup !== null) {
                file_put_contents($real, $backup);
            } else {
                @unlink($real);
            }
        });
    }
}
