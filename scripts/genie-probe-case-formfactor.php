<?php
// Measure EVERY field a consumer could read before declaring the case
// form-factor gap real. On 2026-09-28 I called a whole catalogue "empty" by
// reading one field when the data was in another - so name, description and
// specs all get measured here, and the evidence is printed.
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__ . '/genie-prod-guard.php';

use Illuminate\Support\Facades\DB;

$cases = DB::table('components')
    ->where('category_id', DB::table('categories')->where('name', 'Case')->value('id'))
    ->where('active', 1)
    ->get();

$patterns = [
    'Micro-ATX' => '/micro[-\s]?atx|\bm-?atx\b/',
    'Mini-ITX'  => '/mini[-\s]?itx|\bmitx\b/',
    'ITX'       => '/\bitx\b/',
    'E-ATX'     => '/\be-?atx\b|extended atx/',
    'ATX'       => '/\batx\b/',
];

$hit = ['name' => 0, 'description' => 0, 'specs' => 0];
$onlyWhere = [];
foreach ($cases as $c) {
    $name = (string) $c->name;
    $desc = (string) $c->description;
    $specs = $c->specs ?? null;
    if (is_string($specs)) {
        $specs = json_decode($specs, true);
    }
    $specs = is_array($specs) ? json_encode($specs) : '';

    $fields = ['name' => $name, 'description' => $desc, 'specs' => (string) $specs];
    $foundIn = [];
    foreach ($patterns as $label => $re) {
        foreach ($fields as $field => $text) {
            if ($text !== '' && preg_match($re, $text)) {
                $hit[$field]++;
                $foundIn[$field] = true;
            }
        }
    }
    if ($foundIn !== []) {
        $onlyWhere[implode('+', array_keys($foundIn))] = ($onlyWhere[implode('+', array_keys($foundIn))] ?? 0) + 1;
    }
}

$total = $cases->count();
echo "cases: $total\n\n";
echo "form factor matchable, per field (a case can match in several):\n";
foreach ($hit as $field => $n) {
    printf("  %-12s %4d  (%.1f%%)\n", $field, $n, $n / $total * 100);
}

echo "\ncombination of fields that produced a match:\n";
arsort($onlyWhere);
foreach ($onlyWhere as $combo => $n) {
    printf("  %-30s %4d\n", $combo, $n);
}

$any = count($onlyWhere);
printf("\nany field: %d of %d (%.1f%%)\n", $any, $total, $any / $total * 100);

echo "\nsample of cases matched ONLY via description:\n";
$n = 0;
foreach ($cases as $c) {
    $desc = (string) $c->description;
    $nm = (string) $c->name;
    $matchDesc = false;
    foreach ($patterns as $re) {
        if ($desc !== '' && preg_match($re, $desc)) {
            $matchDesc = true;
        }
        if ($nm !== '' && preg_match($re, $nm)) {
            $matchDesc = false;
            break;
        }
    }
    if ($matchDesc && $n++ < 5) {
        printf("  %-44s | %s\n", substr($nm, 0, 44), substr(preg_replace('/\s+/', ' ', $desc), 0, 60));
    }
}
