<?php
/**
 * DEFINITIVE orphan audit for public/img/components.
 *
 * A file is an ORPHAN if no components row has that id. This matters because a
 * previous note claimed 2,604 of 3,290 cached files were orphans, while a later
 * measurement implied the opposite. Before pruning anything, the number has to
 * be exact: pruning on the wrong figure would delete ~2,600 real product images.
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$driver = config('database.default');
echo "DRIVER    : {$driver} / " . config('database.connections.' . $driver . '.driver') . PHP_EOL;
$dbName = config('database.connections.' . $driver . '.database');
echo "DATABASE  : {$dbName}" . PHP_EOL . PHP_EOL;

$imgDir = public_path('img/components');
$files = glob($imgDir . '/*.jpg') ?: [];

// All component ids that EXIST as rows (active or not).
$allIds = array_flip(array_map('strval', DB::table('components')->pluck('id')->all()));
$activeIds = array_flip(array_map('strval', DB::table('components')->where('active', true)->pluck('id')->all()));

$orphan = []; $matchedActive = []; $matchedInactive = []; $tiny = [];
$bytesOrphan = 0; $bytesActive = 0;

foreach ($files as $f) {
    $id = pathinfo($f, PATHINFO_FILENAME);
    $sz = filesize($f);
    if ($sz < 4096) $tiny[] = [$id, $sz];
    if (!isset($allIds[$id])) {
        $orphan[] = [$id, $sz];
        $bytesOrphan += $sz;
    } elseif (isset($activeIds[$id])) {
        $matchedActive[] = [$id, $sz];
        $bytesActive += $sz;
    } else {
        $matchedInactive[] = [$id, $sz];
    }
}

$mb = fn($b) => number_format($b / 1048576, 1) . ' MB';

echo "files in cache dir                      : " . count($files) . PHP_EOL;
echo "  MATCH an ACTIVE component row         : " . count($matchedActive) . "  ({$mb($bytesActive)})" . PHP_EOL;
echo "  MATCH an INACTIVE component row       : " . count($matchedInactive) . PHP_EOL;
echo "  ORPHANS (no component row at all)     : " . count($orphan) . "  ({$mb($bytesOrphan)})" . PHP_EOL;
echo "  of which suspiciously small (<4KB)    : " . count($tiny) . PHP_EOL . PHP_EOL;

echo "id range of matching-active files       : ";
$ma = array_map(fn($r) => (int) $r[0], $matchedActive);
echo (count($ma) ? min($ma) . ' - ' . max($ma) : 'n/a') . PHP_EOL;

echo "id range of orphan files                : ";
$or = array_map(fn($r) => (int) $r[0], $orphan);
echo (count($or) ? min($or) . ' - ' . max($or) : 'n/a') . PHP_EOL . PHP_EOL;

echo "ORPHAN SAMPLE (first 15) - id | bytes" . PHP_EOL;
foreach (array_slice($orphan, 0, 15) as [$id, $sz]) echo "  {$id} | {$sz}" . PHP_EOL;

echo PHP_EOL . "SMALL-FILE SAMPLE (<4KB, first 10) - id | bytes" . PHP_EOL;
foreach (array_slice($tiny, 0, 10) as [$id, $sz]) {
    $has = isset($allIds[$id]) ? 'has row' : 'ORPHAN';
    echo "  {$id} | {$sz} | {$has}" . PHP_EOL;
}
