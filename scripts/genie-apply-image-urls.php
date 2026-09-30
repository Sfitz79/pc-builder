<?php
// PRODUCTION image_url write, with a snapshot taken first.
//
// Deliberately refuses to run unless it is pointed at Neon, because a bare
// `php artisan components:populate-image-urls` silently operates on the local
// sqlite database and reports a different catalogue entirely.
//
// Usage:
//   php scripts/genie-apply-image-urls.php            (dry run, no writes)
//   php scripts/genie-apply-image-urls.php --apply    (snapshot, then write)
$root = __DIR__ . '/..';
require $root . '/vendor/autoload.php';

$envFile = $root . '/.env.production.neon';
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    putenv(trim($k).'='.trim(trim($v), '"'));
    $_ENV[trim($k)] = trim(trim($v), '"');
}
putenv('APP_ENV=production');

$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

if (DB::connection()->getDriverName() !== 'pgsql') {
    fwrite(STDERR, "ABORT: expected the pgsql (Neon) driver, got '".DB::connection()->getDriverName()."'.\n");
    exit(2);
}

$apply = in_array('--apply', $argv, true);
$stamp = date('Ymd-His');
$snapshotDir = $root.'/database/snapshots';
if (! is_dir($snapshotDir)) {
    mkdir($snapshotDir, 0755, true);
}

echo "=== production precondition ===\n";
printf("  driver=%s host=%s\n", DB::connection()->getDriverName(), DB::connection()->getConfig()['host']);
printf("  components total : %d\n", DB::table('components')->count());
printf("  image_url set    : %d\n", DB::table('components')->whereNotNull('image_url')->where('image_url', '<>', '')->count());
printf("  mode             : %s\n", $apply ? 'APPLY (will write)' : 'DRY-RUN (no writes)');

echo "\n=== snapshotting every row that could be affected ===\n";
// Everything, not just the rows we intend to change: a rollback must be able to
// restore any value we might clobber, including a pre-existing vendor URL.
$before = DB::table('components')->orderBy('id')->get(['id', 'image_url']);
$path = $snapshotDir.'/pre-image-url-'.$stamp.'.json';
file_put_contents($path, json_encode($before, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
printf("  %d row(s) -> %s\n", $before->count(), basename($path));

echo "\n=== running components:populate-image-urls ===\n";
$exit = Artisan::call('components:populate-image-urls', [
    '--apply' => $apply,
    '--expect-driver' => 'pgsql',
]);
echo Artisan::output();
echo "\ncommand exit: {$exit}\n";

echo "\n=== after ===\n";
$set = DB::table('components')->whereNotNull('image_url')->where('image_url', '<>', '')->count();
$bad = DB::table('components')->where('image_url', 'like', '%localhost%')->count();
$notRelative = DB::table('components')->whereNotNull('image_url')->where('image_url', '<>', '')
    ->where('image_url', 'not like', '/%')->count();
printf("  image_url set        : %d\n", $set);
printf("  containing localhost : %d  (MUST be 0)\n", $bad);
printf("  not root-relative    : %d  (pre-existing vendor URLs)\n", $notRelative);

if ($bad > 0) {
    fwrite(STDERR, "\nFATAL: {$bad} row(s) contain localhost. Rolling back from ".$path."\n");
    exit(3);
}

echo "\nspot check:\n";
foreach (DB::table('components')->whereNotNull('image_url')->where('image_url', '<>', '')->orderBy('id')->limit(5)->get() as $r) {
    printf("  [%d] %s\n", $r->id, $r->image_url);
}
echo "\nsnapshot for rollback: {$path}\n";
