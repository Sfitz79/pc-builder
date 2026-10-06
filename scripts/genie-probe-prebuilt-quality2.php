<?php
// Follow-up to the quality probe. Three specific questions before any rule is
// written, because each changes the design:
//
//  1. The probe said "MISSING CATEGORY Cooler" yet prebuilts.json contains a
//     Cooler part. Which is true? (assembler used $ids['Cooler'])
//  2. Motherboard specs exist on 3/400 rows. So a chipset-based board filter
//     cannot work - confirm how sparse it really is.
//  3. RAM runs to £6562 and storage to £17635. Those are outliers that a
//     price-ordered picker can land on, so they need explicit bounds.
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== ALL CATEGORIES (exact names) ===\n";
foreach (DB::table('categories')->get() as $c) {
    $n = DB::table('components')->where('category_id', $c->id)->where('active', 1)->count();
    printf("  id=%-4d %-24s active=%d\n", $c->id, $c->name, $n);
}

echo "\n=== MOTHERBOARD: how many rows carry ANY spec? ===\n";
$mb = DB::table('categories')->where('name', 'Motherboard')->first();
if ($mb) {
    $rows = DB::table('components')->where('category_id', $mb->id)->where('active', 1)->get();
    $withSpecs = 0;
    foreach ($rows as $r) {
        $s = $r->specs;
        if (is_string($s)) {
            $s = json_decode($s, true) ?: [];
        }
        if (is_array($s) && $s) {
            $withSpecs++;
        }
    }
    echo "  total active : " . $rows->count() . "\n";
    echo "  with specs   : $withSpecs\n";

    // Cheapest 10 AM5 boards - what the price-first picker actually reaches.
    echo "\n  cheapest 10 AM5 boards (what orderBy(price) returns):\n";
    foreach (DB::table('components')->where('category_id', $mb->id)->where('active', 1)
        ->where('socket', 'AM5')->whereNotNull('price')->orderBy('price')->limit(10)->get() as $r) {
        printf("    £%-8.2f %-46s socket=%-9s socket_count=%s\n",
            $r->price, substr($r->name, 0, 46), $r->socket ?? '?', $r->socket_count ?? 'n/a');
    }

    // How is socket expressed? (the previous report showed socket/socket_count)
    $cols = DB::getSchemaBuilder()->getColumnListing('components');
    echo "\n  socket-related columns: " . implode(', ', array_filter($cols, fn ($c) => str_contains($c, 'socket'))) . "\n";
}
