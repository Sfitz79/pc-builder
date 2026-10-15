<?php

/**
 * READ-ONLY. What business-relevant hardware does the catalogue ACTUALLY hold?
 *
 * The claim under test: "PCTG Business quotes gaming hardware because we have no
 * workstation parts". That is a claim about DATA, so it has to be measured
 * against the columns and JSON spec keys the consumer actually reads - not
 * against the product name, which is the mistake recorded in AGENTS.md rule 1
 * that nearly deleted every GPU, RAM and storage row.
 *
 * Nothing is written.
 */

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Component;

echo 'db        : '.config('database.default').' ('.DB::connection()->getDriverName().")\n";
echo 'active    : '.Component::query()->active()->count()." components\n\n";

$cats = Component::query()->active()->with('category')->get()->groupBy(fn ($c) => $c->category?->slug ?? 'misc');
foreach ($cats as $slug => $rows) {
    echo sprintf("  %-14s %d\n", $slug, $rows->count());
}
echo "\n";

/**
 * Count rows matching a name pattern OR any spec key/value.
 */
$probe = function (string $label, string $category, array $nameLike, array $specKeys = [], array $specNeedles = []) use ($cats) {
    $rows = $cats[$category] ?? collect();
    if ($rows->isEmpty()) {
        printf("  %-34s %-14s (no category)\n", $label, $category);

        return;
    }

    // Guard against a falsy needle. stripos($haystack, false) coerces to "" in
    // PHP 8 and an empty needle matches at offset 0, so a single accidental
    // false in this list makes the probe report EVERY row as a hit - which is
    // exactly what happened on the first run of this script.
    $needles = array_values(array_filter($nameLike, fn ($n) => is_string($n) && $n !== ''));

    $byName = $needles === []
        ? collect()
        : $rows->filter(fn ($c) => collect($needles)->contains(
            fn ($n) => stripos((string) $c->name, $n) !== false
        ));

    $bySpec = collect();
    if ($specNeedles !== []) {
        $bySpec = $rows->filter(function ($c) use ($specKeys, $specNeedles) {
            $specs = is_array($c->specs) ? $c->specs : [];

            foreach ($specKeys as $key) {
                $hay = json_encode($specs[$key] ?? null);
                foreach ($specNeedles as $needle) {
                    if ($hay && stripos($hay, $needle) !== false) {
                        return true;
                    }
                }
            }

            return false;
        });
    }

    $union = $byName->merge($bySpec)->unique('id');

    printf(
        "  %-34s %-14s name=%-4d spec=%-4d union=%-4d\n",
        $label,
        $category,
        $byName->count(),
        $bySpec->count(),
        $union->count()
    );

    foreach ($union->take(4) as $sample) {
        printf("        e.g. %-58s £%s\n", mb_substr($sample->name, 0, 58), number_format((float) $sample->price, 2));
    }
};

echo "CPU\n";
$probe('Threadripper', 'cpu', ['Threadripper']);
$probe('Xeon / EPYC / server', 'cpu', ['Xeon', 'EPYC']);

echo "\nMOTHERBOARD\n";
$probe('workstation chipset', 'motherboard', [], ['chipset'], ['W680', 'C266', 'WRX90', 'TRX50', 'X670E', 'X870']);
$probe('server / workstation', 'motherboard', ['W680', 'C266', 'WRX90', 'TRX50', 'Pro WS', 'ProArt', 'X670E', 'X870', 'Z890', 'B860']);

echo "\nRAM\n";
$probe('ECC / RDIMM', 'ram', ['ECC', 'RDIMM', 'Registered'], ['type', 'speed', 'memory_type'], ['ecc', 'rdimm', 'registered']);

echo "\nSTORAGE\n";
$probe('enterprise / hdd / sas', 'storage', ['IronWolf', 'WD Red', 'Seagate', 'SAS', 'Exos', 'NHDD', 'Purple']);

echo "\nGPU\n";
$probe('professional / pro', 'gpu', ['Radeon Pro', 'RTX A', 'Quadro', 'Tesla', 'Arc Pro', 'W7900', 'W7800', 'W6800', 'Radeon Pro'], ['memory', 'chipset', 'name'], ['pro', 'quadro', 'a4000', 'a5000', 'a6000', 'w7900']);

echo "\nCASE\n";
$probe('multi-bay / nas / rack', 'case', ['Jonsbo', 'N1', 'N2', 'N3', 'N4', 'N8', 'NAS', 'Rack', '432', '825', '424', '437', '453', '464', 'Meshify', 'O11', 'H6', '400M', 'D9', 'R9', 'Vault']);

echo "\nCOOLER\n";
$probe('quiet air / no aio', 'cooler', ['Noctua', 'Air', 'Tower', 'Peerless', 'Phantom']);

/*
 * STRUCTURAL: how many drives can one build even hold?
 *
 * The picker allocates ONE storage category. A NAS is defined by having several
 * bays filled, so this is a capability question, not a catalogue one.
 */
echo "\nSTRUCTURAL\n";
$storageSlots = 1;
$categories = \App\Models\Category::query()->active()->get()->pluck('slug')->all();
$storageSlots = count(array_filter($categories, fn ($s) => $s === 'storage'));
printf("  storage categories in the schema : %d  (a NAS needs 2+)\n", $storageSlots);
$extra = \App\Models\Category::query()->whereIn('slug', ['storage2', 'storage_2', 'storage_b', 'nas_storage'])->count();
printf("  secondary storage categories    : %d\n", $extra);
echo "  => " . ($storageSlots + $extra > 1
    ? "multi-drive builds are representable\n"
    : "NOT representable: the picker can only ever quote ONE drive\n");

echo "\nNO WRITES PERFORMED - read-only.\n";
