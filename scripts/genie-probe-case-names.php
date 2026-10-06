<?php
// Resolve the 7-vs-0 disagreement between two of my own scripts by printing
// the actual matching rows. Two scripts disagreeing about the same fact means
// neither is trustworthy until the evidence is on screen.
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__ . '/genie-prod-guard.php';

use Illuminate\Support\Facades\DB;

$caseId = DB::table('categories')->where('name', 'Case')->value('id');

$rows = DB::table('components')->where('category_id', $caseId)->where('active', 1)->get();
echo "active cases: " . $rows->count() . "\n";
echo "with a price: " . $rows->filter(fn ($r) => $r->price !== null)->count() . "\n\n";

$patterns = [
    'Micro-ATX' => '/micro[-\s]?atx|\bm-?atx\b/',
    'Mini-ITX'  => '/mini[-\s]?itx|\bmitx\b/',
    'ITX'       => '/\bitx\b/',
    'E-ATX'     => '/\be-?atx\b|extended atx/',
    'ATX'       => '/\batx\b/',
];

$found = 0;
foreach ($rows as $r) {
    $name = (string) $r->name;
    $desc = (string) $r->description;
    $specs = $r->specs ?? null;
    if (is_string($specs)) {
        $specs = json_decode($specs, true);
    }
    $specsTxt = is_array($specs) ? json_encode($specs) : '';

    foreach ($patterns as $label => $re) {
        foreach (['name' => $name, 'desc' => $desc, 'specs' => $specsTxt] as $field => $text) {
            if ($text !== '' && preg_match($re, $text, $m)) {
                $found++;
                printf("  [%s] field=%-6s matched %-14s  name=%s\n", $r->id, $field, "'" . $m[0] . "'", substr($name, 0, 48));
            }
        }
    }
}
echo "\ntotal field-matches: $found\n";

echo "\n-- 10 sample case names (what do they actually look like?) --\n";
foreach ($rows->take(10) as $r) {
    printf("  %-52s desc=%s\n", substr((string) $r->name, 0, 52), $r->description ? 'yes' : 'none');
}
