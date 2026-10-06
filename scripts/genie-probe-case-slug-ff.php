<?php

/**
 * Case form factor, field #4: the PCPP source_url SLUG.
 *
 * The name, description and specs fields were all measured and all empty of
 * form-factor data. The slug was the fourth field and was never checked - the
 * same mistake a third time, on a different field. Examples:
 *   montech-xr-atx-mid-tower-case-xr-b
 *   cooler-master-masterbox-q300l-microatx-mini-tower-cas
 *
 * Longest match first: "atx" is a substring of "microatx", so a naive /\batx\b/
 * after /microatx/ is fine, but the reverse order mislabels every mATX case.
 * Ordering here is the whole correctness argument.
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__ . '/genie-prod-guard.php';

use Illuminate\Support\Facades\DB;

$caseId = DB::table('categories')->where('name', 'Case')->value('id');
$rows = DB::table('components')->where('category_id', $caseId)->where('active', 1)
    ->get(['id', 'name', 'source_url']);

/** Ordered longest/most-specific first. */
const FF_RULES = [
    'Micro-ATX' => ['/\bmicro[-\s]?atx\b/', '/\bm[-\s]?atx\b/', '/\bmatx\b/'],
    'Mini-ITX'  => ['/\bmini[-\s]?itx\b/', '/\bmitx\b/'],
    'E-ATX'     => ['/\be[-\s]?atx\b/', '/\bextended[-\s]?atx\b/'],
    'ITX'       => ['/\bitx\b/'],
    'ATX'       => ['/\batx\b/'],
];

function ffFromSlug(string $slug): ?array
{
    $s = strtolower($slug);
    foreach (FF_RULES as $ff => $patterns) {
        foreach ($patterns as $re) {
            if (preg_match($re, $s, $m)) {
                return [$ff, $m[0]];
            }
        }
    }
    return null;
}

$resolved = 0;
$unresolved = [];
$dist = [];

foreach ($rows as $r) {
    $slug = (string) $r->source_url;
    $hit = ffFromSlug($slug);
    if ($hit !== null) {
        $resolved++;
        $dist[$hit[0]] = ($dist[$hit[0]] ?? 0) + 1;
    } else {
        $unresolved[] = $r;
    }
}

$total = $rows->count();
printf("active cases                         : %d\n", $total);
printf("form factor recovered from the slug  : %d (%.1f%%)\n", $resolved, $resolved / $total * 100);
printf("still unresolved                     : %d\n", count($unresolved));

echo "\n-- distribution --\n";
arsort($dist);
foreach ($dist as $ff => $n) {
    printf("  %-12s %4d\n", $ff, $n);
}

echo "\n-- sample of resolved (proof it is not guessing) --\n";
$n = 0;
foreach ($rows as $r) {
    $hit = ffFromSlug((string) $r->source_url);
    if ($hit !== null && $n++ < 8) {
        printf("  %-12s %-42s <- matched %s\n", $hit[0], substr((string) $r->name, 0, 42), $hit[1]);
    }
}

echo "\n-- the unresolved ones (these need Byparr + the PCPP spec page) --\n";
foreach (array_slice($unresolved, 0, 15) as $r) {
    printf("  %-44s %s\n", substr((string) $r->name, 0, 44), substr(basename((string) $r->source_url), -46));
}
if (count($unresolved) > 15) {
    printf("  ... and %d more\n", count($unresolved) - 15);
}
