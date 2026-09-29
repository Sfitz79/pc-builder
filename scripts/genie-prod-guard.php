<?php

/**
 * Production database guard.
 *
 * Every script that is SUPPOSED to read production must prove it is on
 * production before it reports anything. Printing the driver is not enough:
 * a script that connects to the local SQLite file, prints "sqlite" into its
 * own output and exits 0 can be read at a glance as a valid production
 * result. That is precisely the mistake made on 2026-09-28, where a scan
 * reported local data as if it were the live catalogue.
 *
 * This guard fails LOUDLY and non-zero, so a caller that ignores stdout still
 * cannot miss it.
 *
 * USAGE (after the Laravel kernel is bootstrapped):
 *     require __DIR__ . '/genie-prod-guard.php';
 */

declare(strict_types=1);

if (!class_exists(\Illuminate\Support\Facades\DB::class)) {
    fwrite(STDERR, "genie-prod-guard: run this AFTER bootstrap so the DB facade exists\n");
    exit(2);
}

$__driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();
$__expected = getenv('GENIE_EXPECT_DRIVER') ?: 'pgsql';

if ($__driver !== $__expected) {
    fwrite(STDERR, sprintf(
        "genie-prod-guard: ABORTING. Expected driver '%s' but connected to '%s'.\n"
        . "  This script reads PRODUCTION data. Refusing to report local or wrong-database\n"
        . "  results as if they were production. Apply the env file before bootstrap.\n",
        $__expected,
        (string) $__driver
    ));
    exit(3);
}

// Provenance stamp: every production report carries this, so a pasted output
// always records which database it came from.
//
// Guarded with defined() because a test file may require this guard once per
// test method. The unguarded version raised
// "Constant GENIE_PROD_VERIFIED already defined" as a PHP warning, which
// PHPUnit turned into an ErrorException and failed every test in the class
// after the first - a false failure caused by the guard itself, not by the
// code under test.
if (! defined('GENIE_PROD_VERIFIED')) {
    define('GENIE_PROD_VERIFIED', true);
}
