<?php
// Genie 2026-09-28: read the PRODUCTION catalogue for the first time. Local .env
// is SQLite, so every number reported before this point was measured against a
// local file. This is the real thing.
//
// NOTE: production's components.active is a real Postgres BOOLEAN, so raw SQL
// must use `active` / `active = true`, never `active = 1`. Comparing a boolean
// to an integer is the error PostgreSQL rejects (SQLSTATE 42883). This is why
// the shipped code was corrected from where('active', 1) to where('active', true).
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();


require __DIR__ . '/genie-prod-guard.php';
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo '=== production schema ==='.PHP_EOL;
echo 'db driver        : '.DB::connection()->getDriverName().PHP_EOL;
echo 'server version   : '.DB::selectOne('select version() as v')->v.PHP_EOL;
echo 'components table : '.(Schema::hasTable('components') ? 'present' : 'MISSING').PHP_EOL;
echo 'price_checked_at : '.(Schema::hasColumn('components', 'price_checked_at') ? 'EXISTS' : 'MISSING').PHP_EOL;
echo 'active column    : '.DB::getSchemaBuilder()->getColumnType('components', 'active').PHP_EOL;

echo PHP_EOL.'=== migration state ==='.PHP_EOL;
$migrations = DB::table('migrations')->orderBy('batch')->orderBy('migration')->pluck('migration');
printf('applied migrations: %d, newest: %s'.PHP_EOL, $migrations->count(), $migrations->last());

echo PHP_EOL.'=== production catalogue ==='.PHP_EOL;
$q = fn (string $sql) => (int) DB::selectOne($sql)->c;
printf('active components            : %d'.PHP_EOL, $q('select count(*) c from components where active is true'));
printf('total components             : %d'.PHP_EOL, $q('select count(*) c from components'));
printf('active with source_url       : %d'.PHP_EOL, $q("select count(*) c from components where active is true and source_url is not null and source_url <> ''"));
printf('active with a PCPP url       : %d'.PHP_EOL, $q("select count(*) c from components where active is true and source_url like 'https://uk.pcpartpicker.com/product/%'"));
printf('active with stock > 0        : %d'.PHP_EOL, $q('select count(*) c from components where active is true and stock > 0'));

// price_checked_at only exists once the pending migration has run, so these
// counts are reported as "n/a" until then rather than exploding the script.
$hasChecked = Schema::hasColumn('components', 'price_checked_at');
if ($hasChecked) {
    printf('active with price_checked_at : %d'.PHP_EOL, $q('select count(*) c from components where active is true and price_checked_at is not null'));
    printf('active, stale >7d            : %d'.PHP_EOL, $q("select count(*) c from components where active is true and (price_checked_at is null or price_checked_at < now() - interval '7 days')"));
} else {
    printf('active with price_checked_at : n/a (column not migrated yet)'.PHP_EOL);
    printf('active, stale >7d            : n/a (column not migrated yet)'.PHP_EOL);
}

echo PHP_EOL.'=== category breakdown (active) ==='.PHP_EOL;
foreach (DB::select('select cat.name, count(*) c from components comp join categories cat on cat.id = comp.category_id where comp.active is true group by cat.name order by c desc') as $row) {
    printf('  %-28s %d'.PHP_EOL, $row->name, $row->c);
}

echo PHP_EOL.'=== sanity: price distribution (active, stock>0) ==='.PHP_EOL;
$s = DB::selectOne('select min(price) lo, max(price) hi, avg(price) av, count(*) c from components where active is true and stock > 0');
printf('  n=%d  min=GBP %.2f  avg=GBP %.2f  max=GBP %.2f'.PHP_EOL, $s->c, $s->lo, $s->av, $s->hi);

echo PHP_EOL.'=== top 5 by price (the corrupt-row check) ==='.PHP_EOL;
foreach (DB::select('select id, name, price from components where active is true order by price desc limit 5') as $row) {
    printf('  [%d] GBP %9.2f  %s'.PHP_EOL, $row->id, $row->price, mb_substr((string) $row->name, 0, 60));
}
