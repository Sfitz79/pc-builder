<?php
/**
 * Exercise App\Support\Tls against the real config, then against a config where
 * nothing resolves - because the important property is the NEGATIVE one.
 *
 * The bug this class exists to prevent is `verify => <path that does not exist>`,
 * which turns every outbound HTTPS call into "unable to get local issuer
 * certificate". A syntax check cannot see that. So the last case here asserts that
 * guzzleOptions() returns an EMPTY ARRAY when no bundle is found, which is what
 * tells Guzzle to use its own default.
 */

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\Tls;

function line(string $s): void { echo $s . PHP_EOL; }

// ── 1. the real environment ────────────────────────────────────────────────
line('=== 1. as configured on this machine ===');
$path = Tls::bundlePath();
line('  bundlePath():   ' . ($path === null ? 'NULL - system default' : $path));
line('  guzzleOptions(): ' . json_encode(Tls::guzzleOptions()));

if ($path !== null && !is_file($path)) {
    line('  FAIL: returned a path that does not exist. That is the exact defect');
    line('        this class is supposed to make impossible.');
    exit(1);
}

/*
 * REGRESSION GUARD, and the reason this file exists.
 *
 * The code this replaced read `is_file('C:\Users\simon\cacert.pem')` and that
 * file genuinely exists on the Boss's machine. The first version of config/tls.php
 * searched only the project root and system paths, returned NULL here, and quietly
 * stopped passing `verify` on Windows - a cleanup that broke the thing it cleaned.
 *
 * So: if a bundle is discoverable on this machine at all, it MUST be found. Any
 * future edit to the candidate lists has to keep this green.
 */
if ($path === null) {
    $knownBundle = getenv('USERPROFILE') . DIRECTORY_SEPARATOR . 'cacert.pem';
    if (is_file($knownBundle)) {
        line('  FAIL: no bundle resolved, but ' . $knownBundle . ' exists.');
        line('        The hardcoded line this replaced DID find it, so this is a');
        line('        regression - local TLS just stopped being verified.');
        exit(1);
    }
    line('  (no bundle anywhere on this machine; system default is correct)');
} else {
    line('  OK: found a real bundle, matching the behaviour of the old code.');
}

// ── 2. memoised ────────────────────────────────────────────────────────────
line('');
line('=== 2. memoisation ===');
$a = Tls::bundlePath();
$b = Tls::bundlePath();
line('  same value on repeat calls: ' . ($a === $b ? 'yes' : 'NO - fail'));
if ($a !== $b) { exit(1); }

// ── 3. THE IMPORTANT ONE: nothing resolvable ───────────────────────────────
line('');
line('=== 3. no bundle anywhere - must fall back cleanly, NOT to a bad path ===');
config([
    'tls.path' => null,
    'tls.local_candidates' => ['definitely-not-here.pem'],
    'tls.home_candidates' => ['definitely-not-here.pem'],
    'tls.system_candidates' => ['/no/such/ca-bundle.pem', '/also/missing.pem'],
]);
Tls::reset();
$none = Tls::bundlePath();
$opts = Tls::guzzleOptions();
line('  bundlePath():   ' . ($none === null ? 'NULL' : $none));
line('  guzzleOptions(): ' . json_encode($opts));

if ($none !== null) {
    line('  FAIL: invented a bundle that does not exist.');
    exit(1);
}
if ($opts !== []) {
    line('  FAIL: returned Guzzle options pointing at a missing file. This is the');
    line('        defect that turns a working TLS handshake into a cert error.');
    exit(1);
}
line('  OK: null bundle -> no verify option -> Guzzle uses its own default.');

// ── 4. an explicit environment override wins ───────────────────────────────
line('');
line('=== 4. an explicit override is honoured ===');
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tls-probe-' . getmypid() . '.pem';
file_put_contents($tmp, "# not a real bundle, the resolver only checks that it exists\n");
config([
    'tls.path' => $tmp,
    'tls.local_candidates' => [],
    'tls.system_candidates' => [],
]);
Tls::reset();
$override = Tls::guzzleOptions();
line('  guzzleOptions(): ' . json_encode($override));
if (($override['verify'] ?? null) !== $tmp) {
    line('  FAIL: the override was ignored.');
    unlink($tmp);
    exit(1);
}
line('  OK: the configured path is used verbatim.');
unlink($tmp);

// ── 5. a configured path that does not exist must NOT be used ─────────────
line('');
line('=== 5. a configured path that does not exist is ignored, not passed on ===');
config([
    'tls.path' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'definitely-missing-' . getmypid() . '.pem',
    'tls.local_candidates' => [],
    'tls.system_candidates' => ['/no/such.pem'],
]);
Tls::reset();
$ignored = Tls::guzzleOptions();
line('  guzzleOptions(): ' . json_encode($ignored));
if ($ignored !== []) {
    line('  FAIL: passed a non-existent path to Guzzle. Silent TLS failure.');
    exit(1);
}
line('  OK: a bad configured path degrades to the system default, not to a crash.');

line('');
line('TLS PROOF PASSED');