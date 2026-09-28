<?php
// Genie 2026-09-28: exact list of unpublishable rows + reason.
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Component;
use App\Services\PriceIntegrityService;
use App\Services\PcppPriceService;

$integrity = app(PriceIntegrityService::class);
$pcpp = app(PcppPriceService::class);
$quarantined = $integrity->quarantinedIds();
$cats = App\Models\Category::pluck('slug', 'id');

// Unpublishable = NOT (not quarantined AND evidenced AND fresh).
$bad = Component::where('active', 1)->get()->filter(
    fn ($c) => ! (
        ! isset($quarantined[(int) $c->id])
        && trim((string) $c->source_url) !== ''
        && $integrity->freshness($c)['fresh']
    )
);

$rows = [];
foreach ($bad as $c) {
    $src = trim((string) $c->source_url);
    $reasons = [];
    if (isset($quarantined[(int) $c->id])) { $reasons[] = 'quarantined'; }
    if ($src === '')                              { $reasons[] = 'no-source'; }
    elseif (! $pcpp->supports($src))              { $reasons[] = 'unsupported-url'; }
    if (! $c->price_checked_at)                   { $reasons[] = 'stale'; }
    elseif (! $integrity->freshness($c)['fresh'])  { $reasons[] = 'expired'; }

    $rows[] = [
        'id' => $c->id,
        'cat' => $cats[$c->category_id] ?? '?',
        'name' => (string) $c->name,
        'price' => (float) $c->price,
        'reasons' => $reasons,
        'src' => $src,
    ];
}

printf("UNPUBLISHABLE: %d\n\n", count($rows));

$byReason = [];
foreach ($rows as $r) {
    foreach ($r['reasons'] as $x) { $byReason[$x][] = $r; }
}
ksort($byReason);
foreach ($byReason as $reason => $set) {
    printf("  %-16s %d\n", $reason, count($set));
}

echo "\n--- the 21 with no source_url (ids for a merchant hunt) ---\n";
foreach ($rows as $r) {
    if (in_array('no-source', $r['reasons'], true)) {
        printf("  %-5s %-11s GBP %8.2f  %s\n", $r['id'], $r['cat'], $r['price'], mb_substr($r['name'], 0, 58));
    }
}

$out = dirname(__DIR__).'/storage/framework/unpublishable.json';
@mkdir(dirname($out), 0777, true);
file_put_contents($out, json_encode($rows, JSON_PRETTY_PRINT));
echo "\nwrote {$out}\n";
