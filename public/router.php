<?php

/**
 * Router script for PHP's built-in server.
 *
 * WHY THIS FILE EXISTS
 *
 * The dev server was started as:
 *
 *     php -S 127.0.0.1:8100 -t public public/index.php
 *
 * Passing index.php as the ROUTER makes the built-in server invoke it for EVERY
 * request, including ones that name a real file on disk. The router decides what
 * happens: returning false hands the request back to the server's static file
 * handler, anything else means "this script handled it".
 *
 * public/index.php never returns false - it boots Laravel and renders a response.
 * So Laravel swallowed every static asset. Measured on this server:
 *
 *     /build/assets/app.js                 -> 404
 *     /resources/js/pc-viewport.js         -> 404
 *     /textures/pbr/*.jpg                  -> 200 text/html  (the SPA fallback)
 *     /favicon.ico                         -> 200 text/html
 *
 * The texture case is the dangerous one, and it already produced a false pass.
 * A verification script fetched all 12 PBR maps, got HTTP 200 with a few hundred
 * KB of "content", and reported "all 12 maps served". They were HTML. It only
 * surfaced because a later framebuffer diff came back byte-identical while every
 * status code was green - a 200 is not proof a static asset was served, so the
 * gate now asserts content-type and decoded image width, not just the status.
 *
 * USAGE
 *
 *     php -S 127.0.0.1:8100 -t public public/router.php
 *
 * (artisan serve is the supported command, but it 500s with a MissingAppKeyException
 * on this Windows host, hence the built-in server.)
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';

// Reject traversal outright rather than relying on the realpath check below: a
// request for /../.env should not be resolved and compared, it should never get
// this far.
if (str_contains($path, '..')) {
    http_response_code(400);
    exit;
}

$candidate = __DIR__ . '/' . ltrim(rawurldecode($path), '/');

if ($path !== '/' && is_file($candidate)) {
    // Only hand back files that really sit inside public/, so a symlink or a
    // crafted path cannot walk out of the document root.
    $real = realpath($candidate);
    $root = realpath(__DIR__);
    if ($real !== false && $root !== false && str_starts_with($real, $root)) {
        return false;
    }
}

require __DIR__ . '/index.php';