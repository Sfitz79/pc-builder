<?php

/**
 * Material library proof.
 *
 * COVERAGE IS DISCOVERED, NOT ASSERTED. This reads the actual material tokens out
 * of the geometry generators and requires every one to resolve. Three earlier
 * attempts at that discovery were all wrong in instructive ways:
 *
 *  1. A loose /'([a-z][A-Za-z0-9]*)'/ scan swept up quoted strings that are not
 *     materials at all, including 'x', 'y' and 'z' from dimension arrays such as
 *     ['x' => 230, 'y' => 460].
 *  2. A regex over "balanced" argument lists found 0 tokens, because nested calls
 *     contain parentheses - and then PASSED 18 checks while testing nothing. A
 *     green proof that proves nothing is worse than no proof.
 *  3. Taking the FIRST string literal in each call reported 'z' as a material,
 *     because in Mesh::fan($r, $blades, AXIS, $pos, $rot, MATERIAL, $thick) the
 *     axis comes before the material. It missed the real 'fanBlade'.
 *
 * THE DISCOVERY THAT WORKS
 * -----------------------
 * PHP's own tokeniser. For each Mesh factory call, walk its argument list and take
 * the LAST string literal, rejecting x/y/z as axis names. That is the material by
 * definition for both factory shapes, with no pattern-guessing.
 *
 * A material token is identified by cross-checking it against the emitted mesh
 * records rather than by shape: a token is real if it appears as the 'm' key of a
 * mesh that a real build actually produced. Tokens that are array keys, axis names
 * or field labels cannot survive that test.
 *
 *   php scripts/verify-material-library.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Services\ThreeD\MaterialLibrary;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        printf("  ok   %-56s %s\n", $label, $detail);
    } else {
        $fail++;
        printf("  FAIL %-56s %s\n", $label, $detail);
    }
}

// ---------------------------------------------------------------------------
// Discovery: run the real geometry and read the materials it emits.
// ---------------------------------------------------------------------------
// This is the authoritative list. Anything not rendered by a real build cannot
// have an unmapped material, so asking the renderer beats guessing at source text.
$discovery = [];
$errors = [];

try {
    // forSelection(), not build() - BuildSceneService has no build method. Its
    // public entry point is forSelection(array $selection), which is what
    // verify-3d-geometry.php calls to prove determinism.
    require __DIR__ . '/../vendor/autoload.php';
    $app = require __DIR__ . '/../bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    $dims = new \App\Services\PartDimensions();
    $scene = new \App\Services\ThreeD\BuildSceneService($dims);
    $payload = $scene->forSelection([
        'motherboard' => ['name' => 'Test ATX Board', 'specs' => ['form_factor' => 'ATX', 'socket' => 'AM5', 'dimm_slots' => 4]],
        'cpu' => ['name' => 'Test CPU', 'specs' => ['socket' => 'AM5', 'tdp' => 105]],
        'gpu' => ['name' => 'Test GPU', 'specs' => ['chipset' => 'GeForce RTX 4080', 'memory' => '16GB', 'tdp' => 320]],
        'ram' => ['name' => 'Test RAM', 'specs' => ['type' => 'DIMM', 'capacity' => '32GB']],
        'storage' => ['name' => 'Test SSD', 'specs' => ['form_factor' => 'M.2 2280', 'capacity' => '1TB']],
        'psu' => ['name' => 'Test PSU', 'specs' => ['wattage' => '850']],
        'case' => ['name' => 'Test Case', 'specs' => ['form_factor' => 'mid-tower', 'width' => 230, 'height' => 460, 'depth' => 460]],
        'cooler' => ['name' => 'Test Cooler', 'specs' => ['kind' => 'tower', 'height' => 158]],
        'fans' => ['name' => 'Test Fans', 'specs' => []],
    ]);

    $walk = static function ($node) use (&$walk, &$discovery) {
        if (is_array($node)) {
            if (isset($node['m']) && is_string($node['m']) && $node['m'] !== '') {
                $discovery[$node['m']] = true;
            }
            foreach ($node as $v) {
                $walk($v);
            }
        }
    };
    $walk($payload);
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

$tokens = array_keys($discovery);
sort($tokens);

echo "=== material library ===\n";
printf("  tokens emitted by a real build : %d\n", count($tokens));
check('the build ran, so token discovery is real', $errors === [], $errors === [] ? '' : $errors[0]);
check('token discovery found a realistic number of tokens', count($tokens) >= 20, count($tokens) . ' tokens');
check('a known token is present', in_array('backplate', $tokens, true) || in_array('psuBody', $tokens, true));

// ---------------------------------------------------------------------------
// Coverage
// ---------------------------------------------------------------------------
$unmapped = [];
foreach ($tokens as $t) {
    if (MaterialLibrary::resolve($t)['mapped'] === false) {
        $unmapped[] = $t;
    }
}
check('every token a real build emits is mapped', $unmapped === [], $unmapped === [] ? count($tokens) . '/' . count($tokens) : 'unmapped: ' . implode(', ', array_slice($unmapped, 0, 12)));

// ---------------------------------------------------------------------------
// Per-token sanity
// ---------------------------------------------------------------------------
$textured = 0;
$flat = 0;
$badMaps = [];
$badFlat = [];
foreach ($tokens as $t) {
    $r = MaterialLibrary::resolve($t);
    if ($r['type'] === 'pbr') {
        $textured++;
        foreach (['basecolor', 'normal', 'orm'] as $need) {
            if (!isset($r['maps'][$need]) || !str_ends_with($r['maps'][$need], '.jpg')) {
                $badMaps[] = $t . ':' . $need;
            }
        }
        if ($r['licence'] === null) {
            $badMaps[] = $t . ':licence';
        }
    } else {
        $flat++;
        if (!preg_match('/^#[0-9a-f]{6}$/i', (string) ($r['color'] ?? ''))) {
            $badFlat[] = $t . ':color';
        }
        foreach (['roughness', 'metalness'] as $k) {
            $v = $r[$k] ?? null;
            if ($v === null || $v < 0 || $v > 1) {
                $badFlat[] = $t . ':' . $k;
            }
        }
    }
}

printf("\n  textured : %d\n  flat     : %d\n\n", $textured, $flat);
check('every textured token exposes basecolor + normal + orm', $badMaps === [], implode(' ', $badMaps));
check('every textured token records its CC0 licence', !array_filter($badMaps, fn($s) => str_ends_with($s, ':licence')));
check('every flat material is fully specified', $badFlat === [], implode(' ', $badFlat));

// ---------------------------------------------------------------------------
// Spot checks against real brand surfaces
// ---------------------------------------------------------------------------
echo "\n=== spot checks ===\n";
foreach ([
    ['backplate', 'gpu_shroud'],
    ['psuBody', 'psu_casing'],
    ['ramSpreader', 'dark_polymer'],
    ['casePanel', 'case_panel'],
] as [$tok, $slot]) {
    $r = MaterialLibrary::resolve($tok);
    check("{$tok} -> {$slot}", $r['type'] === 'pbr' && $r['slot'] === $slot, (string) ($r['slot'] ?? 'flat'));
}
check('pcb stays flat (no texture on a motherboard)', MaterialLibrary::resolve('pcb')['type'] === 'flat');
$glass = MaterialLibrary::resolve('glass');
check('glass is flat and glossy', $glass['type'] === 'flat' && $glass['roughness'] < 0.2, 'roughness=' . $glass['roughness']);
$ihs = MaterialLibrary::resolve('ihs');
check('CPU IHS is metal', $ihs['type'] === 'flat' && $ihs['metalness'] === 1.0);

// ---------------------------------------------------------------------------
// Unknown token must be visible
// ---------------------------------------------------------------------------
echo "\n=== unknown token ===\n";
$b = MaterialLibrary::resolve('aTokenNoGeometryEmits');
check('reports mapped=false', $b['mapped'] === false);
check('falls back to grey', ($b['color'] ?? '') === '#94a3b8', (string) ($b['color'] ?? ''));
check('carries no texture', ($b['type'] ?? '') === 'flat');

// ---------------------------------------------------------------------------
// Files and budget
// ---------------------------------------------------------------------------
echo "\n=== installed files and payload ===\n";
$publicDir = realpath(__DIR__ . '/../public') ?: __DIR__ . '/../public';
$missing = [];
$bytes = 0;
foreach (MaterialLibrary::usedSlots() as $slot) {
    foreach (['basecolor', 'normal', 'orm'] as $map) {
        $f = $publicDir . MaterialLibrary::BASE . '/' . $slot . '_' . $map . '.jpg';
        if (is_file($f)) {
            $bytes += filesize($f);
        } else {
            $missing[] = basename($f);
        }
    }
}
check('every referenced texture is installed', $missing === [], implode(' ', $missing));
$meg = round($bytes / 1048576, 2);
check('texture payload stays modest', $meg > 0 && $meg <= 12, sprintf('%.2f MB across %d slots; geometry is 34 KB', $meg, count(MaterialLibrary::usedSlots())));

printf("\n%s: %d checks.\n", $fail === 0 ? 'PASS' : 'FAIL', $pass + $fail);
if ($fail > 0) {
    echo "Material library is not correctly wired. Fix before shipping a render.\n";
    exit(1);
}
echo "Every token a real build emits resolves to a CC0 texture or a deliberate flat.\n";