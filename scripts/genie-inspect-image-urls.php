<?php
/** Inspect the raw image_url values that feed the downloader for the first rows. */
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

$names = ['Lian Li O11 Vision', 'NZXT H6 Flow RGB', 'PNY VCG508016TFXPB1'];
foreach ($names as $n) {
    $r = DB::table('components')->where('name', $n)->first(['id', 'name', 'image_url', 'source_url']);
    echo "NAME: {$n}" . PHP_EOL;
    if (!$r) { echo "  (no local row)" . PHP_EOL . PHP_EOL; continue; }
    echo "  id         : {$r->id}" . PHP_EOL;
    echo "  image_url  : [" . $r->image_url . "]" . PHP_EOL;
    echo "  len        : " . strlen((string)$r->image_url) . PHP_EOL;
    echo "  source_url : [" . substr((string)$r->source_url, 0, 90) . "]" . PHP_EOL;
    echo "  hexdump    : " . bin2hex(substr((string)$r->image_url, 0, 24)) . PHP_EOL;
    echo PHP_EOL;
}

// Distribution of suspicious prefixes across every CDN url we hold.
$all = DB::table('components')->whereNotNull('image_url')->where('image_url', 'like', 'http%')->pluck('image_url');
$bad = $all->filter(fn($x) => !preg_match('#^https://[a-z0-9.\-]+\.[a-z]{2,}/\S+$#i', (string)$x));
echo "total CDN urls        : " . $all->count() . PHP_EOL;
echo "NOT matching https URL: " . $bad->count() . PHP_EOL;
foreach (array_slice($bad->take(8)->all(), 0, 8) as $b) {
    echo "  [" . $b . "] len=" . strlen((string)$b) . PHP_EOL;
}
