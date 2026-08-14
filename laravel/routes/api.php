<?php

use App\Http\Controllers\AdminBusinessContextController;
use App\Http\Controllers\AdminClientController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\AdminLogsController;
use App\Http\Controllers\AdminPermissionController;
use App\Http\Controllers\AdminSchemaController;
use App\Http\Controllers\AdminUsageController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AnalyzeController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClientProfileController;
use App\Http\Controllers\ClientSchemaController;
use App\Http\Controllers\ClientStaffAdminController;
use App\Http\Controllers\ClientUsageController;
use App\Http\Controllers\InternalDataController;
use App\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);

// Internal data-plane endpoint (called by Python, authenticated via
// delegation token forwarded from the client instance).
Route::post('/internal/data/v1/query', [InternalDataController::class, 'query']);

// Turn-completion endpoint (called by Python). Reports the usage deltas of a
// finished turn so billing is decoupled from the SSE relay. Deduped on
// turn_uuid. Delegation-token authenticated like /query.
Route::post('/internal/data/v1/turn-complete', [InternalDataController::class, 'turnComplete']);

// Public-key registry (called by Python at startup/refresh). Loopback-only
// since it returns only public keys. Python binds to 127.0.0.1.
Route::middleware('localhost')->get('/internal/public-keys', [InternalDataController::class, 'publicKeys']);

// System audit events (called by Python). Python relays its own events and
// those reported by client instances to the admin instance, which appends
// them to its daily system-audit log file. Loopback-only like public-keys.
Route::middleware('localhost')->post('/internal/events', [InternalDataController::class, 'reportEvent']);

Route::middleware('auth:sanctum')->group(function () {
    Route::middleware(['client.allowed', 'instance.client'])->post('/analyze', [AnalyzeController::class, 'analyze']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/user', [AuthController::class, 'user']);

    // Client-facing routes
        Route::middleware(['client.allowed', 'instance.client'])->prefix('client')->group(function () {
        Route::get('/sessions', [SessionController::class, 'index']);
        Route::post('/sessions', [SessionController::class, 'store']);
        Route::put('/sessions/{session}', [SessionController::class, 'update']);
        Route::get('/sessions/{session}/history', [SessionController::class, 'history']);
        Route::delete('/sessions/{session}', [SessionController::class, 'destroy']);
        Route::get('/sessions/{session}/artifacts/{name}/download', [SessionController::class, 'download']);

        Route::get('/schema', [ClientSchemaController::class, 'show']);

        Route::get('/usage', [ClientUsageController::class, 'index']);

        Route::get('/profile', [ClientProfileController::class, 'show']);

        // Client-admin-only staff management
        Route::middleware('client.admin')->prefix('staff')->group(function () {
            Route::get('/', [ClientStaffAdminController::class, 'index']);
            Route::post('/{user}/deactivate', [ClientStaffAdminController::class, 'deactivate'])->whereNumber('user');
            Route::post('/{user}/activate', [ClientStaffAdminController::class, 'activate'])->whereNumber('user');
        });
    });

    // Admin routes
    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('/dashboard', [AdminDashboardController::class, 'index']);
        Route::get('/logs', [AdminLogsController::class, 'index']);
        Route::get('/usage', [AdminUsageController::class, 'index']);
        Route::get('/schema/metadata', [AdminSchemaController::class, 'listMetadata']);
        Route::put('/schema/metadata/{id}', [AdminSchemaController::class, 'update'])->whereNumber('id');
        Route::delete('/schema/metadata/{id}', [AdminSchemaController::class, 'destroy'])->whereNumber('id');
        Route::post('/schema/discover', [AdminSchemaController::class, 'discover']);
        Route::post('/schema/import-descriptions', [AdminSchemaController::class, 'importDescriptions']);

        Route::post('clients/test-connection', [AdminClientController::class, 'testConnection']);
        Route::post('clients/{client}/deactivate', [AdminClientController::class, 'deactivate'])->whereNumber('client');
        Route::post('clients/{client}/reactivate', [AdminClientController::class, 'reactivate'])->whereNumber('client');
        Route::get('clients/{client}/users/discover', [AdminClientController::class, 'discoverUsers'])->whereNumber('client');
        Route::post('clients/{client}/users/sync', [AdminClientController::class, 'syncUsers'])->whereNumber('client');
        Route::post('clients/{client}/users/{user}/deactivate', [AdminClientController::class, 'deactivateUser'])->whereNumber('client')->whereNumber('user');
        Route::post('clients/{client}/users/{user}/activate', [AdminClientController::class, 'activateUser'])->whereNumber('client')->whereNumber('user');
        Route::post('clients/{client}/keys/generate', [AdminClientController::class, 'generateKeys'])->whereNumber('client');
        Route::get('clients/{client}/dashboard', [AdminClientController::class, 'dashboard'])->whereNumber('client');
        Route::apiResource('clients', AdminClientController::class)->except(['create', 'edit']);

        Route::get('business-context/config', [AdminBusinessContextController::class, 'config']);
        Route::apiResource('business-context', AdminBusinessContextController::class);

        Route::apiResource('permission-tokens', AdminPermissionController::class);

        Route::apiResource('users', AdminUserController::class);
    });
});
