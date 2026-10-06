<?php
// Dump production categories with live active counts. Read-only.
// A temp FILE, never `php -r`: PowerShell strips the double quotes inside it
// and you get a parse error that looks like a PHP bug.
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__ . '/genie-prod-guard.php';

use Illuminate\Support\Facades\DB;

echo "driver: " . DB::connection()->getDriverName() . "\n";
foreach (DB::table('categories')->orderBy('id')->get() as $c) {
    $n = DB::table('components')->where('category_id', $c->id)->where('active', 1)->count();
    printf("  id=%-3d %-30s active=%d\n", $c->id, $c->name, $n);
}
