<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'client.allowed' => \App\Http\Middleware\EnsureClientAllowed::class,
            'client.admin' => \App\Http\Middleware\ClientAdminMiddleware::class,
            'instance.client' => \App\Http\Middleware\EnsureInstanceClient::class,
            'localhost' => \App\Http\Middleware\LocalhostOnly::class,
        ]);
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->redirectGuestsTo('/login');
        $middleware->validateCsrfTokens(except: [
            '/api/analyze',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
