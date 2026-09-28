<?php
// Genie 2026-09-28: rows stamped by the merchant gate should carry a RETAILER
// URL, never amazon.co.uk (the gate rejects Amazon's interstitial). Also worth
// knowing how many confirmed rows still only point at PCPP, which is weaker
// evidence than a retailer page that was actually read.
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Component;

$recent = Component::where('active', 1)
    ->where('price_checked_at', '>=', now()->subHours(8))
    ->get();

$byHost = [];
foreach ($recent as $row) {
    $host = (string) (parse_url((string) $row->source_url, PHP_URL_HOST) ?: '(none)');
    $byHost[$host] = ($byHost[$host] ?? 0) + 1;
}
arsort($byHost);

echo "recently stamped rows: ".$recent->count()."\n\n";
echo "--- source_url host distribution ---\n";
foreach ($byHost as $host => $n) {
    printf("  %-28s %d\n", $host, $n);
}

$amazon = $recent->filter(fn ($r) => str_contains((string) $r->source_url, 'amazon.co.uk'));
echo "\nstamped rows still pointing at Amazon: ".$amazon->count()."\n";
foreach ($amazon->take(10) as $row) {
    printf("  [%d] %s  checked %s\n", $row->id, mb_substr((string) $row->name, 0, 44), (string) $row->price_checked_at);
}
