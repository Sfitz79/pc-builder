<?php
/**
 * PROVES THE LICENCE STORE IS REALLY DURABLE, by reading from a DIFFERENT machine
 * than the one that wrote it.
 *
 * The Vercel function issued and bound keys in the serverless runtime; this script runs
 * on the Boss's machine against the same Postgres. If the row is here, the write
 * persisted - which is the entire point, because /tmp and the read-only deployment
 * bundle could never do this.
 *
 * Usage: php scripts/genie-verify-licence-store.php
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== ACTIVATION WRITTEN BY THE VERCEL FUNCTION ===\n";
$key = 'PCTG-FA42411E-56BE3A5A-C82D6FD4';
$r = DB::table('pctl_licences')->where('licence_key', $key)->first();

if (!$r) {
    fwrite(STDERR, "  MISSING - the deployment did not persist.\n");
    exit(1);
}
printf("  key         : %s\n", $r->licence_key);
printf("  tier        : %s\n", $r->tier);
printf("  bound hwid  : %s\n", $r->bound_hwid ?: '(unbound)');
printf("  activations : %s\n", $r->activations);
printf("  activated   : %s\n", $r->first_activated_at ?: '(never)');

$ok = $r->bound_hwid === 'MACHINE-A' && (int) $r->activations === 2;

echo "\n=== FREE-LICENCE HOLE CLOSED (impossible/demo keys revoked) ===\n";
foreach (['PCTG-A1B2C3D4-E5F6G7H8-I9J0K1L2', 'PCTG-M2N3O4P5-Q6R7S8T9-U0V1W2X3', 'IHAVETHEGAMERSEDGE'] as $k) {
    $x = DB::table('pctl_licences')->where('licence_key', $k)->first();
    printf("  %-32s %s\n", $k, ($x && $x->revoked_at) ? 'REVOKED (good)' : 'NOT REVOKED  <-- BAD');
}

echo "\n=== TOTALS ===\n";
printf("  rows            : %d\n", DB::table('pctl_licences')->count());
printf("  active/unbound  : %d\n", DB::table('pctl_licences')->whereNull('revoked_at')->whereNull('bound_hwid')->count());
printf("  active/bound    : %d\n", DB::table('pctl_licences')->whereNull('revoked_at')->whereNotNull('bound_hwid')->count());
printf("  revoked         : %d\n", DB::table('pctl_licences')->whereNotNull('revoked_at')->count());
printf("  bound to A      : %d\n", DB::table('pctl_licences')->where('bound_hwid', 'MACHINE-A')->count());

echo "\n" . ($ok ? "PASS - the store is durable and correctly bound." : "CHECK - read the values above.") . "\n";
