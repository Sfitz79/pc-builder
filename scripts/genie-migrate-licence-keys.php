<?php
/**
 * MIGRATES THE 18 KEYS THAT WERE HARDCODED INSIDE api/validate.js INTO POSTGRES.
 *
 * THE PROBLEM THIS FIXES
 * ----------------------
 * validate.js shipped those keys in the deployed JavaScript, which means every one of
 * them was readable by anyone who loaded the page or read the source: a free PRO
 * licence, handed out in advance, to everyone. Two of them are obvious test placeholders
 * (PCTG-A1B2C3D4-E5F6G7H8-I9J0K1L2 and its sibling - they contain the letters G-Z,
 * which the real generator, 8 hex bytes, can never produce).
 *
 * WHAT THIS DOES
 *   - Imports all of them so any genuine earlier holder is not locked out by the move.
 *   - REVOKES the demo key and the two impossible test keys, which is safe because
 *     neither can correspond to a real sale.
 *   - Leaves genuine-looking keys ACTIVE and unbound, so first activation still claims
 *     them to the buyer's machine exactly as before.
 *
 * SAFE TO RUN MORE THAN ONCE (ON CONFLICT DO NOTHING).
 *
 * Usage: php scripts/genie-migrate-licence-keys.php [--dry-run]
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$dryRun = in_array('--dry-run', $argv, true);
echo "=== MIGRATE BAKED-IN LICENCE KEYS ===\n";
echo $dryRun ? "MODE: DRY RUN - nothing will be written\n\n" : "MODE: LIVE\n\n";

/** key => [tier, revoked?] */
$keys = [
    'IHAVETHEGAMERSEDGE'                => ['pro',      true],
    'PCTG-8C0C28B9-AAD3EE89-A8FB37E2'    => ['standard', false],
    'PCTG-42CE3A09-9895BEAF-9D0BC6F7'    => ['standard', false],
    'PCTG-FA42411E-56BE3A5A-C82D6FD4'    => ['pro',      false],
    'PCTG-E2A7014B-DC8666D7-F83821CE'    => ['pro',      false],
    'PCTG-CC98C0DC-36AA4E69-8646A227'    => ['fps',      false],
    'PCTG-6C8256E0-9E3CEB40-8E0D288F'    => ['fps',      false],
    'PCTG-62AA8365-5CD80BF7-CDE69998'    => ['security', false],
    'PCTG-7A3452BD-36662E24-1033EE7C'    => ['security', false],
    'PCTG-C273AFA2-5B38AFBB-5CD03C53'    => ['internet', false],
    'PCTG-B095B337-CE60DB7E-F7129ECB'    => ['internet', false],
    'PCTG-D8156D50-EBA44443-EFD49DBA'    => ['winfix',   false],
    'PCTG-EE39D076-090DD8D2-91694E1E'    => ['winfix',   false],
    'PCTG-35BC5049-8C0952D7-8BEBB3C6'    => ['deep',     false],
    'PCTG-0F10F5C4-8B49826A-B8922DE6'    => ['deep',     false],
    'PCTG-A1B2C3D4-E5F6G7H8-I9J0K1L2'    => ['repair',   true],
    'PCTG-M2N3O4P5-Q6R7S8T9-U0V1W2X3'    => ['repair',   true],
];

$active = 0; $revoked = 0; $existing = 0;
foreach ($keys as $key => [$tier, $isRevoked]) {
    $found = DB::table('pctl_licences')->where('licence_key', $key)->exists();
    if ($found) { $existing++; printf("  %-32s already migrated\n", $key); continue; }

    if ($dryRun) {
        printf("  %-32s tier=%-9s would %s\n", $key, $tier, $isRevoked ? 'REVOKE' : 'import active');
    } else {
        DB::table('pctl_licences')->insert([
            'licence_key' => $key,
            'product'     => 'optimizer',
            'tier'        => $tier,
            'paypal_mode' => 'legacy',
            'revoked_at'  => $isRevoked ? now() : null,
            'notes'       => $isRevoked
                ? 'Revoked on migration: demo or impossible test placeholder that was published in validate.js source.'
                : 'Migrated from the hardcoded key list in validate.js.',
        ]);
        printf("  %-32s tier=%-9s %s\n", $key, $tier, $isRevoked ? 'REVOKED' : 'imported');
    }
    $isRevoked ? $revoked++ : $active++;
}

echo "\n" . ($dryRun ? 'would import' : 'imported') . " active : {$active}\n";
echo ($dryRun ? 'would revoke' : 'revoked') . "        : {$revoked}\n";
echo "already present              : {$existing}\n";
echo "\ntotal rows in pctl_licences : " . DB::table('pctl_licences')->count() . "\n";
if ($dryRun) { echo "\nDRY RUN - re-run without --dry-run to apply.\n"; }
