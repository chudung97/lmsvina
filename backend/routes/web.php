<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

Route::get('/{path?}', function (?string $path = null) {
    $dist = realpath(base_path('../dist'));
    $index = $dist ? $dist.DIRECTORY_SEPARATOR.'index.html' : null;
    if (! $dist || ! $index || ! is_file($index)) {
        return response('Frontend build not found. Run npm run build.', 503);
    }

    if ($path) {
        $file = realpath($dist.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path));
        if ($file && str_starts_with($file, $dist.DIRECTORY_SEPARATOR) && is_file($file)) {
            $contentType = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
                'css' => 'text/css; charset=UTF-8',
                'js', 'mjs' => 'text/javascript; charset=UTF-8',
                default => null,
            };

            return response()->file($file, $contentType ? ['Content-Type' => $contentType] : []);
        }
    }

    $version = (string) filemtime(__FILE__);
    $html = preg_replace(
        '/((?:src|href)="\/assets\/[^"]+)(?=")/',
        '$1?v='.$version,
        File::get($index),
    );

    return response($html)
        ->header('Content-Type', 'text/html; charset=UTF-8')
        ->header('Cache-Control', 'no-store');
})->where('path', '^(?!api(?:/|$)|up$).*$');
