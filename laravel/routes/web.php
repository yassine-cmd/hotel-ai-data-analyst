<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SPA Entry Point
|--------------------------------------------------------------------------
| The compiled front-end (Vite React build) is copied into public/ as
| index.html + assets. Any GET request that is not a real file and is not a
| back-end route (everything under /api, /sanctum, or the /up health check)
| is served the SPA shell so client-side routing (React Router) can render.
|
| The standard public/.htaccess already rewrites non-file requests to
| index.php; this catch-all only has to return the shell. This keeps the
| deployment server-agnostic (works under Apache mod_rewrite as well as an
| nginx try_files that falls through to index.php).
*/
Route::get('/{any}', function () {
    $index = public_path('index.html');

    if (! file_exists($index)) {
        abort(404);
    }

    return response()->file($index, ['Content-Type' => 'text/html']);
})->where('any', '^(?!api|sanctum|up).*$');
