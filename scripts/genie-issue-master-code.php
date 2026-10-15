<?php
/**
 * Issues Simon's MASTER CODE - a real licence row in Postgres, not a hardcoded string.
 *
 * WHY NOT BAKED INTO THE APP
 * --------------------------
 * A master code inside app.asar is a free Pro licence for anyone who opens the file:
 * 7-Zip, Ctrl+F, done. That is exactly the defect v1.2.0 shipped and that was removed
 * on 2026-10-09. It also leaks the moment one customer posts it, at which point every
 * licence is worthless and the damage is permanent.
 *
 * WHY A DATABASE ROW IS STRICTLY BETTER
 * -------------------------------------
 * The app already asks /api/validate about every key. So the master code needs NO
 * special code path in the app at all - it is simply a licence row like any other:
 *   - nothing to extract from any build
 *   - revocable in one statement, without shipping a new installer
 *   - binds to the Boss's machine on first use, so it cannot be passed around
 *   - auditable: it appears in the same table as every other sale
 *
 * Usage: php scripts/genie-issue-master-code.php
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$NOTES = 'MASTER CODE - Simon (owner). Not for distribution. Revoke by setting revoked_at.';

// Format matches the desktop app parser: 4 segments of 4 bytes = 8 hex chars each.
$key = 'PCTG-MAST-' . strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2)))
     . '-' . strtoupper(bin2hex(random_bytes(2))) . '-' . strtoupper(bin2hex(random_bytes(2)));

$existing = DB::table('pctl_licences')->where('notes', $LIKE ?? 'like', '%MASTER CODE%')->first();
if ($existing) {
    echo "  A master code already exists: {$existing->licence_key}\n";
    echo "  Delete that row first if you want a fresh one.\n";
    exit(1);
}

DB::table('pctl_licences')->insert([
    'licence_key' => $key,
    'product'     => 'optimizer',
    'tier'        => 'pro',
    'paypal_mode' => 'master',
    'notes'       => $NOTES,
]);

echo "\n  MASTER CODE ISSUED\n\n";
echo "  {$key}\n\n";
echo "  tier   : pro (all modules)\n";
echo "  stored : in Postgres only - it is NOT in any installer or binary\n";
echo "  binds  : to the first machine that activates it\n\n";
echo "  Write it down. It cannot be recovered from the app.\n\n";

$check = DB::table('pctl_licences')->where('licence_key', $key)->first();
echo "  verified in database: " . ($check ? 'yes, id ' . $check->id : 'NO - PROBLEM') . "\n";
echo "  revoked: " . ($check->revoked_at ? 'yes' : 'no') . "\n";