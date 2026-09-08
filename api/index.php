<?php

/*
|--------------------------------------------------------------------------
| Vercel Serverless Entry Point
|--------------------------------------------------------------------------
|
| Vercel only serves functions from the `api/` directory. This file hands
| every request to Laravel's normal public/index.php front controller.
|
| IMPORTANT: The PHP runtime runs this file with SCRIPT_NAME set to
| /api/index.php. If we left that as-is, Request::capture() would treat
| "/api" as the app's base directory and strip it from every URL - turning
| "/api/build/recommend" into "/build/recommend" (which breaks the API routes
| and 405s against the GET share-slug route build/{shareSlug}). We therefore
| normalise SCRIPT_NAME/SCRIPT_FILENAME to the web root so Laravel resolves
| the real URL. This is safe because Vercel's catch-all route sends every
| path (/api/*, /builder/*, /) through this single entry point.
|
*/

// Normalise the server path so Laravel sees the app mounted at the web root.
$_SERVER['SCRIPT_NAME']   = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/../public/index.php';
$_SERVER['PHP_SELF']      = '/index.php';

require __DIR__ . '/../public/index.php';
