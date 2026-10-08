<?php
/*
 * READ-ONLY verification of prebuilts.json against PRODUCTION Neon, including
 * every constraint the Boss set on 2026-10-08:
 *
 *   - AMD board gate: AM4 = B550/X570, AM5 = B650/X670/B850/X870.
 *     A520, A620, B450, B840 must never appear.
 *   - APU floor: no Ryzen 3000/4000, nothing below a 5600G/5600GT.
 *   - 16GB DDR4-3200 as 2x8GB on the APU build, read from the module_config
 *     COLUMN (there is no specs.modules key).
 *   - AM4 implies DDR4; an AM4 board with a DDR5 kit cannot be assembled.
 *   - APU build carries no GPU part and is labelled as such.
 *   - Every part id resolves and the advertised total matches live prices.
 */
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__ . '/genie-prod-guard.php';

use App\Models\Component;
use App\Services\AIRecommendationService;

$fail = 0;
$t = function (bool $ok, string $l) use (&$fail) {
    if (! $ok) {
        $fail++;
    }
    printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $l);
};

const GATE = [
    'AM4' => ['/\bB550M?\b/i', '/\bX570\b/i'],
    'AM5' => ['/\bB650[EM]?\b/i', '/\bX670[E]?\b/i', '/\bB850M?\b/i', '/\bX870[EMI]?\b/i'],
];
const FORBIDDEN = ['/\bA520/i', '/\bA620/i', '/\bB450/i', '/\bB840/i'];

$chipsetOf = function (string $name): ?string {
    return preg_match('/\b([ABX]\d{3,4}[A-Z]{0,2})\b/i', $name, $m) ? strtoupper($m[1]) : null;
};

$service = app(AIRecommendationService::class);
$builds = json_decode(file_get_contents(__DIR__ . '/../database/scraped/prebuilts.json'), true);
printf("builds: %d\n\n", count($builds));

foreach ($builds as $b) {
    $name = $b['name'];
    $apu = (bool) ($b['integrated_graphics'] ?? false);
    $socket = (string) ($b['socket'] ?? '');
    printf("=== %s  GBP %s  %s ===\n", $name, number_format((float) $b['total'], 2),
        $apu ? 'INTEGRATED GRAPHICS' : 'discrete GPU');

    $sum = 0.0;
    $byType = [];
    $missing = 0;
    foreach ($b['parts'] as $p) {
        $c = Component::query()->active()->find($p['id'] ?? null);
        if (! $c) {
            printf("     MISSING id=%s %s\n", $p['id'] ?? '?', substr((string) $p['name'], 0, 40));
            $missing++;
            continue;
        }
        $sum += (float) $c->price;
        $byType[$p['type']] = $c;
    }

    $t($missing === 0, 'all part ids resolve on Neon');
    $t(abs($sum - (float) $b['total']) < 0.01,
        sprintf('total matches live prices (%.2f vs %.2f)', $sum, (float) $b['total']));

    // --- board gate -----------------------------------------------------
    if (isset($byType['Motherboard'])) {
        $board = $byType['Motherboard'];
        $chip = (string) $chipsetOf($board->name);
        $allowed = false;
        foreach (GATE[$socket] ?? [] as $rx) {
            if (preg_match($rx, $board->name) === 1) {
                $allowed = true;
            }
        }
        $forbidden = false;
        foreach (FORBIDDEN as $rx) {
            if (preg_match($rx, $board->name) === 1) {
                $forbidden = true;
            }
        }
        $t($allowed, sprintf('board %s passes the AMD gate for %s', $chip ?: $board->name, $socket));
        $t(! $forbidden, 'board is not an excluded A520/A620/B450/B840');
    }

    // --- CPU ------------------------------------------------------------
    if (isset($byType['CPU'])) {
        $cpu = $byType['CPU'];
        $s = is_array($cpu->specs) ? $cpu->specs : [];
        $t($cpu->socket === $socket, "CPU socket {$cpu->socket} matches the declared socket {$socket}");

        if ($apu) {
            // G-series designation as a WORD: 5600G, 5600GT, 8500G. A
            // /\d{4}G\b/ style test misses "5600GT" because G is followed by T,
            // so there is no word boundary after the G.
            $isG = preg_match('/\b\d{4}GT?\b/i', $cpu->name) === 1;
            $t($isG, 'APU is a G-series part (' . $cpu->name . ')');
            $t(preg_match('/\bF\b/i', $cpu->name) !== 1, 'APU is not an F-series chip (F = no iGPU)');
            // Floor: 5600G/5600GT or better. Excludes 3200G/3400G/5500GT.
            $t(preg_match('/\b5[67]00G[TF]?\b/i', $cpu->name) === 1,
                'APU meets the 5600G/GT floor (' . $cpu->name . ')');
            $t(! isset($byType['GPU']), 'APU build has NO gpu part');
            $t(! empty($b['tagline']), 'APU build carries a tagline: ' . ($b['tagline'] ?? 'MISSING'));
            $t(! empty($b['notes']), 'APU build carries explanatory notes');
        }
    }

    // --- memory ---------------------------------------------------------
    if (isset($byType['RAM'])) {
        $ram = $byType['RAM'];
        $s = is_array($ram->specs) ? $ram->specs : (json_decode((string) $ram->specs, true) ?: []);
        $speed = (string) ($s['speed'] ?? '');
        $cap = (int) preg_replace('/[^0-9]/', '', (string) ($s['capacity'] ?? ''));
        $expectGen = $socket === 'AM5' ? 'DDR5' : 'DDR4';
        $t($speed !== '' && stripos($speed, $expectGen) !== false,
            "memory generation {$expectGen} matches socket {$socket} (got '{$speed}')");

        if ($apu) {
            // Same trap as the assembler bug this verifies: "DDR4-3200" parsed
            // by stripping non-digits yields 43200, so strip the generation
            // token BEFORE reading the number or DDR4-2133 looks like 42133.
            $numeric = preg_replace('/DDR[45]/i', '', $speed);
            $mt = (int) preg_replace('/[^0-9]/', '', (string) $numeric);
            $t($mt >= 3200, "memory is 3200 or faster (parsed {$mt} from '{$speed}')");
            $t($cap === 16, "memory is 16GB (got {$cap})");
            $layout = strtolower((string) ($ram->module_config ?? ''));
            $t(preg_match('/2\s*x\s*8/', $layout) === 1,
                "memory is a 2x8 kit (module_config='{$layout}')");
        }
    }

    $t((int) ($b['psu_watts'] ?? 0) > 0, 'PSU wattage recorded');
    $t((float) ($b['headroom'] ?? 0) > 1.0, sprintf('PSU headroom %.2fx', (float) ($b['headroom'] ?? 0)));
    echo "\n";
}

echo $fail === 0 ? "ALL CHECKS PASSED\n" : "{$fail} CHECK(S) FAILED\n";
exit($fail === 0 ? 0 : 1);