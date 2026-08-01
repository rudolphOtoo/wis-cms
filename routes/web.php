<?php

use Illuminate\Support\Facades\Route;

// SPA catch-all. Excludes /api/* so unmatched API paths (including
// non-UUID values for UUID route params) fall through to the API's
// JSON 404 handler instead of returning the SPA HTML.
Route::get('/{any}', function () {
    return view('welcome');
})->where('any', '^(?!api).*$');
