<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return view('welcome');
});

// Serve files stored on the "public" disk even when `public/storage` symlink is missing.
// Frontend expects URLs like: http://127.0.0.1:8000/storage/<path>
Route::get('/storage/{path}', function (string $path) {
    if ($path === '' || str_contains($path, '..')) {
        abort(404);
    }

    $disk = Storage::disk('public');

    if (!$disk->exists($path)) {
        abort(404);
    }

    $absolutePath = $disk->path($path);

    if (!is_file($absolutePath)) {
        abort(404);
    }

    return response()->file($absolutePath, [
        'Cache-Control' => 'public, max-age=86400',
    ]);
})->where('path', '.*');
