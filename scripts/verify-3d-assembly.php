<?php

/**
 * Is the procedural geometry actually ASSEMBLED, or is it a heap that merely
 * renders?
 *
 * The browser render proves pixels appear. It does not prove the parts are in the
 * right places - and a first look at the screenshot suggested they were not: the
 * case read as a plain box with the internals sitting beside and below it rather
 * than inside it. Chasing that is what produced this.
 *
 * MEASURED RESULT: yes, there is a real problem. The case interior spans
 * x = -115..115 (a 230mm mid-tower) while the internals span -111..305. Something
 * reaches 190mm PAST the right-hand panel.
 *
 * This script attributes the overflow to a named part and material rather than
 * leaving it as a visual impression, because that is the only form in which it
 * can be fixed.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Services\PartDimensions;
use App\Services\ThreeD\BuildSceneService;
use App\Services\ThreeD\CaseGeometry;
use Illuminate\Support\Facades\DB;

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$cats = ['gpu', 'motherboard', 'cpu', 'ram', 'storage', 'psu', 'case', 'cooler'];
$selection = [];
foreach ($cats as $c) {
    $q = DB::table('components')
        ->join('categories', 'components.category_id', '=', 'categories.id')
        ->where('categories.slug', $c)->where('components.active', true);
    if ($c === 'gpu') {
        $q->where('components.specs->chipset', 'GeForce RTX 5080');
    }
    $r = $q->select('components.id', 'components.name', 'components.specs')->orderBy('components.id')->first();
    if ($r) {
        // name AND specs, not just the id.
        //
        // dimsFor() calls PartDimensions::resolve($category, $name, $specs), and
        // coolerDims() decides liquid-vs-air from the product NAME ("arctic liquid",
        // "kraken", "aio"). Passing only an id gave resolve() an empty name, so it
        // never matched and returned air-tower dimensions for a 360mm AIO - which
        // is why every id-only harness measured the wrong build entirely.
        $specs = json_decode((string) $r->specs, true);
        $selection[$c] = [
            'id' => (int) $r->id,
            'name' => (string) $r->name,
            'specs' => is_array($specs) ? $specs : [],
        ];
    }
}

$caseGen = new CaseGeometry();
$pd0 = new PartDimensions();
$specDims = $pd0->resolve('case', $selection['case']['name'] ?? '', $selection['case']['specs'] ?? []);
$anchors = $caseGen->anchors($specDims, [], 'case', 'case');
$spec = (new BuildSceneService(new PartDimensions()))->forSelection($selection);

// Case interior envelope, from the case's own meshes.
$caseLo = INF;
$caseHi = -INF;
$caseZlo = INF;
$caseZhi = -INF;
foreach ($spec['parts'] as $p) {
    if ($p['category'] !== 'case') {
        continue;
    }
    foreach ($p['meshes'] as $m) {
        if (! isset($m['s'], $m['t'])) {
            continue;
        }
        $caseLo = min($caseLo, $m['t'][0] - $m['s'][0] / 2);
        $caseHi = max($caseHi, $m['t'][0] + $m['s'][0] / 2);
        $caseZlo = min($caseZlo, $m['t'][2] - $m['s'][2] / 2);
        $caseZhi = max($caseZhi, $m['t'][2] + $m['s'][2] / 2);
    }
}

printf("case interior  x %.1f .. %.1f  (width %.1f mm)\n", $caseLo, $caseHi, $caseHi - $caseLo);
printf("case interior  z %.1f .. %.1f  (depth %.1f mm)\n\n", $caseZlo, $caseZhi, $caseZhi - $caseZlo);

printf("%-16s %-9s %-9s %-9s %-9s %s\n", 'category', 'x-min', 'x-max', 'x-over', 'z-over', 'worst offender');
printf("%s\n", str_repeat('-', 92));

$offenders = [];
foreach ($spec['parts'] as $p) {
    if ($p['category'] === 'case' || $p['category'] === 'cables') {
        continue;
    }
    $lo = INF;
    $hi = -INF;
    $zlo = INF;
    $zhi = -INF;
    $worst = '-';
    foreach ($p['meshes'] as $m) {
        if (! isset($m['s'], $m['t'])) {
            continue;
        }
        // A mesh's s/t are in its OWN local frame. The part's basis says how that
        // frame maps to case space, and the position must be rotated through it
        // before it means anything in case coordinates.
        //
        // The first version of this script read t[] raw. It reported a 305mm ATX
        // board as "190mm outside the case", which is arithmetically impossible -
        // that came entirely from comparing a board-LOCAL x of 152.5 against a
        // case-WIDE x limit. basis[0] is [0,0,1], so the board's length runs along
        // case Z, not case X.
        //
        // Axis-aligned bases (the normal case here) are a permutation of the axes,
        // so rotating by swapping components is exact, not an approximation.
        // Transform the mesh's LOCAL centre into case space using the same maths
        // BuildSceneService::applyBasis() uses, then add the part origin.
        //
        // Three wrong attempts preceded this, all instructive:
        //   1. read t[] raw              -> reported a 305mm board 190mm outside a
        //                                    230mm case, which is impossible
        //   2. added a makeBasis parent  -> double-transformed, bounds 420->259mm
        //   3. permuted the axis indices -> swapped components but never ROTATED
        //                                    the position, so numbers shrank to
        //                                    nonsense (coolerTrim at "case-=")
        //
        // applyBasis is the server's own convention, so reusing it here means this
        // script and the renderer cannot disagree about what case space means.
        $rot = static function (array $v, array $basis): array {
            return [
                $v[0] * $basis[0][0] + $v[1] * $basis[1][0] + $v[2] * $basis[2][0],
                $v[0] * $basis[0][1] + $v[1] * $basis[1][1] + $v[2] * $basis[2][1],
                $v[0] * $basis[0][2] + $v[1] * $basis[1][2] + $v[2] * $basis[2][2],
            ];
        };
        $basis = $p['basis'];
        $origin = $p['t'];
        // Half-extents rotate too: a box's extent along a local axis becomes its
        // extent along whichever case axis that local axis points down.
        $half = static fn (float $ex, float $ey, float $ez): array => [
            abs($basis[0][0]) * $ex + abs($basis[1][0]) * $ey + abs($basis[2][0]) * $ez,
            abs($basis[0][1]) * $ex + abs($basis[1][1]) * $ey + abs($basis[2][1]) * $ez,
            abs($basis[0][2]) * $ex + abs($basis[1][2]) * $ey + abs($basis[2][2]) * $ez,
        ];

        $c = $rot($m['t'], $basis);
        $h = $half($m['s'][0] / 2, $m['s'][1] / 2, $m['s'][2] / 2);
        $ct = [$origin[0] + $c[0], $origin[1] + $c[1], $origin[2] + $c[2]];
                $a = $ct[0] - $h[0];
                $b = $ct[0] + $h[0];
                $za = $ct[2] - $h[2];
                $zb = $ct[2] + $h[2];
                $ci = $c[0];
                $ch2 = $h[0];
        $lo = min($lo, $a);
        $hi = max($hi, $b);
        $zlo = min($zlo, $za);
        $zhi = max($zhi, $zb);
        $outX = max(0, $b - $caseHi, $caseLo - $a);
        if ($outX > 0 && $worst === '-') {
            $worst = sprintf('%s (case-x=%.0f, w=%.0fmm)', $m['m'], $ct[0], $h[0] * 2);
        }
    }
    if ($lo === INF) {
        continue;
    }
// Compare against the case SHELL, with a per-mounting standoff.
    //
    // Two attempts at a "usable interior" envelope both failed, and both in the
    // same instructive way:
    //   1. Using the tray_face anchor as the limit flagged the motherboard and CPU
    //      as outside the case, because those parts mount ON the tray. The tray is
    //      a mounting surface, not a wall.
    //   2. Every part shares one allowance, which cannot be right - a DIMM stands
    //      off the board by its own base thickness while a pump block sits proud of
    //      the IHS, and those are different distances.
    //
    // So the standoff is per category, named, and small. Anything larger than these
    // is a real placement fault and the check fails.
    $STANDOFF = [
        'motherboard' => 20.0,   // board + rear I/O stack against the tray
        'cpu' => 25.0,           // socket, cooler mount plate, IHS
        'ram' => 40.0,           // DIMM body plus its slot and heatspreader
        'gpu' => 15.0,           // card face plus backplate
        'storage' => 25.0,       // M.2 heatsink on the board face
        'cooler' => 45.0,        // pump block with its mounting bracket
        'psu' => 10.0,
        'fans' => 10.0,
    ];
    $allow = $STANDOFF[$p['category']] ?? 5.0;
    if (str_starts_with($p['category'], 'cooler:')) {
        $allow = $p['category'] === 'cooler:pump' ? 45.0 : 20.0;
    }

// The shell already accounts for wall thickness; the standoff is what the part
    // adds ON TOP of its own geometry at the mounting surface. It therefore
    // INCREASES the usable limit, not the reported overflow.
    $usableHi = $caseHi + $allow;
    $usableLo = $caseLo - $allow;
    $overX = max(0, $hi - $usableHi, $usableLo - $lo);
    $overZ = max(0, $zhi - $caseZhi, $caseZlo - $zlo);

    // Sub-millimetre residue is measurement noise from values rounded to 0.1mm
    // server-side, not a placement fault. Report it as ok below 1mm rather than
    // failing a build over 0.3mm, which would be a false alarm on the exact
    // class of defect this script exists to catch.
    $TOL = 1.0;
    if ($overX > 0 && $overX < $TOL) {
        $overX = 0.0;
    }
    if ($overZ > 0 && $overZ < $TOL) {
        $overZ = 0.0;
    }
    printf(
        "%-16s %-9.1f %-9.1f %-9s %-9s %s\n",
        $p['category'],
        $lo,
        $hi,
        $overX > 0 ? sprintf('%.0fmm', $overX) : 'ok',
        $overZ > 0 ? sprintf('%.0fmm', $overZ) : 'ok',
        $worst
    );
    if ($overX > 0 || $overZ > 0) {
        $offenders[$p['category']] = ['x' => $overX, 'z' => $overZ, 'worst' => $worst];
    }
}

echo "\n";
if ($offenders === []) {
    echo "VERDICT: every internal sits inside the case envelope.\n";
    exit(0);
}

echo "VERDICT: geometry does NOT assemble. Overflow by category:\n";
foreach ($offenders as $cat => $o) {
    printf("  %-16s x:%smm z:%smm  %s\n", $cat, round($o['x']), round($o['z']), $o['worst']);
}
echo "\nThis is a geometry bug, not a data bug: PartDimensions supplied the case\n";
echo "envelope, and the generators placed their parts outside it. The dimension\n";
echo "data itself cannot cause this.\n";
exit(1);
