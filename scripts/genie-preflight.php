<?php

/**
 * Genie preflight - mechanical gates for the mistakes that actually happened.
 *
 * Every check below corresponds to a real error made on 2026-09-28, not a
 * hypothetical. Effort does not prevent recurrence; assertions do.
 *
 *   1.  Deploy gate paths must map to REAL routes      (invented /ai-builder)
 *   2.  Production data scripts must assert their DB    (read .neon, queried sqlite)
 *   3.  No inline `php -r` with nested quoting          (PowerShell ate the quotes)
 *   4.  vercel.json must be valid and have no bad keys  (builds[].env rejected)
 *   5.  Secrets must never be deployable                (hand-rolled tar leak)
 *   6.  deploy.ps1 must parse, with no reserved vars    ($host, -ForegroundColor)
 *   7.  Code category strings must match the catalogue  ("CPU Cooler" vs "Cooler")
 *   8.  Image cache must be within its size budget
 *   9.  PayPal config must be resolvable
 *
 * Exit code 0 = all gates passed, safe to deploy.
 * Exit code 1 = at least one gate FAILED. Do not deploy.
 */

$root = dirname(__DIR__);
chdir($root);

$failures = [];
$warnings = [];
$passes = 0;

$ok = function (string $label) use (&$passes) {
    $passes++;
    printf("  PASS  %s\n", $label);
};
$fail = function (string $label, string $detail = '') use (&$failures) {
    $failures[] = $label . ($detail ? " - $detail" : '');
    printf("  FAIL  %s%s\n", $label, $detail ? " - $detail" : '');
};
$warn = function (string $label, string $detail = '') use (&$warnings) {
    $warnings[] = $label . ($detail ? " - $detail" : '');
    printf("  WARN  %s%s\n", $label, $detail ? " - $detail" : '');
};
$section = fn (string $t) => printf("\n=== %s ===\n", $t);

// Strip comment lines before pattern-matching code. A check that matches its own
// explanatory comment is worse than no check at all, because it trains you to
// ignore it. Every code check below runs against code-only text.
$codeOnly = static function (string $src): string {
    $out = '';
    foreach (preg_split('/\R/', $src) as $line) {
        if (!preg_match('/^\s*#/', $line)) {
            $out .= $line . "\n";
        }
    }
    return $out;
};

// ---------------------------------------------------------------- 1. routes ---
$section('1. Deploy gate paths must be real routes');

$deployScript = $root . '/scripts/deploy.ps1';
$gatePaths = [];
if (is_readable($deployScript)) {
    $src = file_get_contents($deployScript);
    // The gate is the array literal of paths passed to the two-domain check.
    if (preg_match('/\$gate\s*=\s*@\((.*?)\)/s', $src, $m)) {
        preg_match_all('#\'([^\']+)\'#', $m[1], $pm);
        $gatePaths = $pm[1];
    }
}

if ($gatePaths === []) {
    $warn('gate paths', 'could not parse the $gate array out of deploy.ps1 - check the regex');
} else {
    // Build the real route table straight from the app. No guessing.
    require $root . '/vendor/autoload.php';
    $app = require_once $root . '/bootstrap/app.php';
    // Routes are registered during kernel bootstrap (Laravel 11 withRouting).
    // Without this the router is EMPTY and every gate path looks fake - a false
    // alarm that would train you to ignore this check.
    $app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
    $routes = [];
    foreach ($app->make('router')->getRoutes() as $r) {
        $routes[] = $r->methods()[0] . ' ' . $r->uri();
    }
    if ($routes === []) {
        $fail('route table', '0 routes registered - cannot validate gate paths');
    } else {
        $ok(count($routes) . ' routes registered');
    }

    $verdicts = [];
    foreach ($gatePaths as $p) {
        // Normalise BOTH sides: strip the leading slash from the path and from
        // the route uri, otherwise the root route ("GET /") can never match "/".
        $uri = ltrim($p, '/');
        $matched = false;
        foreach ($routes as $r) {
            [$method, $routeUri] = array_pad(explode(' ', $r, 2), 2, '');
            $routeUri = ltrim($routeUri, '/');
            $pattern = '#^' . str_replace(
                ['\{[^}]+\}', '\*'],
                ['[^/]+', '.*'],
                preg_quote($routeUri, '#')
            ) . '$#';
            if (preg_match($pattern, $uri)) {
                $matched = true;
                break;
            }
        }
        // Asset paths are legitimately not routes - they are static files.
        if (str_starts_with($uri, 'img/') || str_starts_with($uri, 'css/') || str_starts_with($uri, 'js/')
            || str_starts_with($uri, 'fonts/') || str_starts_with($uri, 'build/') || $uri === 'llms.txt') {
            $ok("  $p (static asset)");
            continue;
        }
        if ($matched) {
            $ok("  $p (route)");
        } else {
            $verdicts[] = $p;
        }
    }
    if ($verdicts === []) {
        $ok(count($gatePaths) . ' gate paths all resolve to real routes or static assets');
    } else {
        $fail('gate paths are not real routes', implode(', ', $verdicts) . ' - this will report a phantom failure');
    }
}

// ------------------------------------------------------------- 2. prod db ----
$section('2. Production data scripts must assert their DB driver');

$neonScripts = [];
foreach (glob($root . '/scripts/*.php') as $f) {
    if (basename($f) === basename(__FILE__)) {
        continue; // never police the policeman
    }
    // Code only: a passing mention in a comment is not production access.
    $src = $codeOnly((string) file_get_contents($f));
    if (str_contains($src, '.env.production.neon') && str_contains($src, 'bootstrap/app.php')) {
        $neonScripts[$f] = $src;
    }
}
if ($neonScripts === []) {
    $warn('neon scripts', 'none found');
} else {
    foreach ($neonScripts as $f => $src) {
        $name = basename($f);
        // Two distinct mistakes, both made on 2026-09-28:
        //   a) reading the production env file but never APPLYING it (queried sqlite)
        //   b) not asserting the driver afterwards
        if (!str_contains($src, 'putenv(') && !str_contains($src, '$_ENV[')) {
            $fail($name . ' reads .env.production.neon but never applies it', 'it will silently query local sqlite');
            continue;
        }
        // The assertion may be inline OR delegated to the shared guard, which
        // asserts pgsql and exits 3 on anything else. The first version only
        // understood the inline form and failed four correctly-guarded scripts.
        $asserts = str_contains($src, 'genie-prod-guard')
            || (str_contains($src, 'getDriverName()') && preg_match('/!==\s*[\'"]pgsql[\'"]/', $src));
        $asserts
            ? $ok("$name applies the env and asserts pgsql")
            : $fail($name . ' touches production but never asserts the driver', 'it can silently query local sqlite');
    }
}

// ------------------------------------------------------- 3. inline php -r ---
$section('3. No fragile inline `php -r` in scripts or docs');

$inline = [];
foreach (['scripts', 'docs'] as $dir) {
    foreach (glob($root . "/$dir/*") as $f) {
        if (!is_file($f) || !is_readable($f)) {
            continue;
        }
        $src = (string) file_get_contents($f);
        if (preg_match('/php\s+-r\s+["\']/', $src)) {
            $inline[] = basename($f);
        }
    }
}
if ($inline === []) {
    $ok('no inline php -r in scripts/docs');
} else {
    $warn('inline php -r found', implode(', ', $inline) . ' - PowerShell mangles the quoting, use a file');
}

// ------------------------------------------------------------ 4. vercel -----
$section('4. vercel.json validity');

$vjPath = $root . '/vercel.json';
if (!is_readable($vjPath)) {
    $fail('vercel.json', 'missing');
} else {
    $vj = json_decode((string) file_get_contents($vjPath), true);
    if (!is_array($vj)) {
        $fail('vercel.json', 'invalid JSON: ' . json_last_error_msg());
    } else {
        $ok('vercel.json is valid JSON');
        // builds[] entries accept src/use/config only. env is NOT valid here.
        foreach ($vj['builds'] ?? [] as $i => $b) {
            $extra = array_diff(array_keys($b), ['src', 'use', 'config']);
            if ($extra !== []) {
                $fail("vercel.json builds[$i]", 'invalid key(s): ' . implode(',', $extra));
            }
        }
        $hasPhp = collect($vj['builds'] ?? [])->contains(fn ($b) => ($b['src'] ?? '') === '/api/index.php');
        $hasPhp ? $ok('php function build entry present') : $fail('php build entry', 'missing /api/index.php');
    }
}

// ----------------------------------------------------------- 5. secrets -----
$section('5. Secrets must never be deployable');

$viPath = $root . '/.vercelignore';
$vi = is_readable($viPath) ? (string) file_get_contents($viPath) : '';
$needles = ['.env', '*.sqlite', '/vendor', '/node_modules'];
foreach ($needles as $n) {
    str_contains($vi, $n) ? $ok("vercelignore covers $n") : $fail("vercelignore missing $n");
}
// A hand-rolled packer is the actual leak vector we hit.
$packers = [];
foreach (glob($root . '/scripts/*.ps1') as $f) {
    $code = '';
    foreach (file($f) as $line) {
        if (!preg_match('/^\s*#/', $line)) {
            $code .= $line;
        }
    }
    if (stripos($code, 'tar.exe') !== false || stripos($code, 'Compress-Archive') !== false) {
        $packers[] = basename($f);
    }
}
if ($packers === []) {
    $ok('no local packer in deploy scripts (no secret-leak path)');
} else {
    $fail('local packer present', implode(', ', $packers) . ' - must let the Vercel CLI apply .vercelignore');
}

// ------------------------------------------------------------ 6. deploy -----
$section('6. deploy.ps1 health');

$ps = (string) file_get_contents($deployScript);
$psCode = $codeOnly($ps);
if (preg_match('/Write-(Error|Warning)\b[^\r\n]*-ForegroundColor/', $psCode)) {
    $fail('deploy.ps1', 'Write-Error/-Warning used with -ForegroundColor (no such parameter)');
} else {
    $ok('no Write-Error -ForegroundColor');
}
preg_match('/^\s*\$(host|pid|args|input|error|home|matches|psitem)\s*=/mi', $psCode, $rm)
    ? $fail('deploy.ps1', 'reserved variable assignment: ' . implode(',', $rm[1]))
    : $ok('no reserved variable assignments');
str_contains($psCode, '--archive=tgz') ? $ok('deploys are archived (1 upload, not N)') : $fail('deploy.ps1', 'no --archive=tgz: 3,290 images will blow the 5,000/day upload limit');

// ------------------------------------------------------------ 7. categories -
$section('7. Code category strings must match the catalogue');

$catStrings = [];
foreach (glob($root . '/app/**/*.php') ?: [] as $f) {
    $src = (string) file_get_contents($f);
    if (preg_match_all("/(?:category|Category)[^\n]{0,40}?'([A-Z][A-Za-z ]{2,14})'/", $src, $m)) {
        foreach ($m[1] as $c) {
            $catStrings[$c] = ($catStrings[$c] ?? 0) + 1;
        }
    }
}
$known = ['CPU', 'Cooler', 'Motherboard', 'GPU', 'RAM', 'Storage', 'PSU', 'Case'];
$unknown = array_diff(array_keys($catStrings), $known);
$unknown === []
    ? $ok('no unrecognised category literals in app/')
    : $warn('category literals', implode(', ', $unknown) . ' - confirm these exist in the categories table');

// -------------------------------------------------------------- 8. images ---
$section('8. Component image cache budget');

$imgDir = $root . '/public/img/components';
if (!is_dir($imgDir)) {
    $warn('image cache', 'directory absent');
} else {
    $files = glob($imgDir . '/*');
    $bytes = array_sum(array_map('filesize', $files));
    $mb = round($bytes / 1048576, 1);
    $mb <= 120
        ? $ok("cache: " . count($files) . " files, {$mb} MB (within budget)")
        : $fail('image cache', "{$mb} MB exceeds the 120 MB budget - downscale before deploying");
}

// ------------------------------------------------------------- 9. paypal ----
$section('9. PayPal configuration');

$svc = (string) file_get_contents($root . '/config/services.php');
str_contains($svc, "'paypal'") ? $ok('config/services.php has a paypal block') : $fail('config/services.php', 'no paypal block');
$clientId = getenv('PAYPAL_CLIENT_ID') ?: (is_readable($root . '/.env')
    ? (preg_match('/^PAYPAL_CLIENT_ID=(.+)$/m', (string) file_get_contents($root . '/.env'), $mm) ? trim($mm[1]) : '')
    : '');
$clientId !== ''
    ? $ok('PAYPAL_CLIENT_ID is set (len ' . strlen($clientId) . ')')
    : $warn('PAYPAL_CLIENT_ID', 'not set - Pay in 3 messaging will not render');

// ------------------------------------------------------------- summary ------
printf("\n%s\n", str_repeat('-', 68));
printf("passed %d   warnings %d   failures %d\n", $passes, count($warnings), count($failures));
foreach ($failures as $f) {
    printf("  FAILED: %s\n", $f);
}
foreach ($warnings as $w) {
    printf("  warn:   %s\n", $w);
}
printf("%s\n", $failures === []
    ? 'PREFLIGHT PASSED - safe to deploy.'
    : 'PREFLIGHT FAILED - do not deploy.');

exit($failures === [] ? 0 : 1);
