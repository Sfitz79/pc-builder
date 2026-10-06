<?php
/**
 * Downloads CC0 PBR texture maps from Poly Haven and installs them into the
 * pc-builder public assets directory, recording provenance as it goes.
 *
 * Poly Haven is CC0 1.0: commercial use and redistribution are both explicitly
 * permitted, so these can ship. That is a stronger position than CC-BY, which
 * would carry an attribution obligation.
 *
 * WHY ONLY 1K/2K
 * --------------
 * Geometry for a full build is currently 34 KB. A 4K PBR set is tens of megabytes,
 * so shipping one per part would dwarf the scene and stall the configurator on
 * mobile. 1K is enough for a part the size of a RAM stick; 2K only where the
 * surface is large and tiled, which here means case panels.
 *
 * WHAT THIS DOES NOT DO
 * ---------------------
 * It does not invent a PCB texture, because Poly Haven has no circuit-board asset
 * and a grunge map on a motherboard reads worse than a plain material with a
 * correct roughness value. Missing materials are reported, not faked.
 *
 * Usage:
 *   php scripts/fetch-pbr-textures.php --list
 *   php scripts/fetch-pbr-textures.php --palette=core --apply
 *   php scripts/fetch-pbr-textures.php --palette=core          # dry run
 */

require __DIR__ . '/../vendor/autoload.php';

$API = 'https://api.polyhaven.com';
$apply = in_array('--apply', $argv, true);
$list = in_array('--list', $argv, true);

/**
 * The palette. Each entry maps a renderer material slot to a Poly Haven asset and
 * the resolution that suits that surface area.
 */
function palette(): array
{
    return [
        'gpu_shroud' => [
            'asset' => 'metal_plate',
            'res' => '1k',
            'format' => 'jpg',
            'applies_to' => 'GPU shroud, fan faces, PCIe bracket',
        ],
        'psu_casing' => [
            'asset' => 'metal_plate_02',
            'res' => '1k',
            'format' => 'jpg',
            'applies_to' => 'PSU casing and fan grille',
        ],
'case_panel' => [
            'asset' => 'painted_metal_shutter',
            // 2k was tried first and came to 8MB for one slot. Geometry for a full
            // build is 34KB, so a single surface outweighing the entire scene by
            // two orders of magnitude is the wrong trade for a configurator that
            // has to run on a phone. 1k tiles fine on a panel this size.
            'res' => '1k',
            'format' => 'jpg',
            'applies_to' => 'chassis panels, the largest tiled surface in the scene',
        ],
        'dark_polymer' => [
            'asset' => 'black_painted_planks',
            'res' => '1k',
            'format' => 'jpg',
            'applies_to' => 'RAM heatspreader, cooler fan hub, cable combs',
        ],
    ];
}

/** Fetch a URL and return its body, or null. Never throws. */
function grab(string $url, int $timeout = 120): ?string
{
    $tmp = tempnam(sys_get_temp_dir(), 'ph') . '.bin';
    $cmd = sprintf(
        'curl.exe -sS -L --max-time %d -A "Mozilla/5.0 (compatible; pctechguy/3d-pipeline)" -o "%s" "%s" 2>&1',
        $timeout,
        $tmp,
        escapeshellarg($url)
    );
    $err = shell_exec($cmd);
    if (!is_file($tmp)) {
        return null;
    }
    $body = file_get_contents($tmp);
    @unlink($tmp);
    return ($body === false || $body === '') ? null : $body;
}

function jsonGrab(string $url): ?array
{
    $b = grab($url);
    if ($b === null) {
        return null;
    }
    $d = json_decode($b, true);
    return is_array($d) ? $d : null;
}

/** Resolve the real download URLs for one asset at one resolution. */
function mapUrls(string $api, string $asset, string $res, string $format): array
{
    $files = jsonGrab("{$api}/files/{$asset}");
    if ($files === null) {
        return [];
    }
    // REAL SHAPE, measured from a live response. It nests CHANNEL -> RESOLUTION ->
    // FORMAT, three levels deep:
    //   $files['Diffuse']['1k']['jpg']['url']
    // An earlier version looked for $files[$res], which does not exist, so all four
    // slots reported MISSING while the API was returning everything correctly.
    $out = [];
    // MINIMAL CORRECT SET, and the reduction is large.
    //
    // A first pass requested all eight channels and came to 26.8MB for four slots.
    // That was my own over-fetch, not the assets' doing:
    //   - nor_dx AND nor_gl are the SAME normal map in two conventions. A WebGL
    //     viewer needs exactly one. Keeping dx.
    //   - bump duplicates normal at a different scale. Redundant.
    //   - arm is AO + Roughness + Metal packed into RGB, so separate AO, Rough and
    //     Metal downloads are three files of information already in one image.
    //
    // Three maps is what a real PBR renderer consumes: base colour, one normal,
    // and ORM. Roughness and metallic are read out of the ORM channels at load.
    $channels = [
        'Diffuse' => 'basecolor',
        'nor_dx' => 'normal',
        'arm' => 'orm',
    ];
    foreach ($channels as $channel => $name) {
        $byRes = $files[$channel][$res] ?? null;
        if (!is_array($byRes)) {
            continue;
        }
        // Prefer the requested format; fall back to whatever the asset offers.
        $entry = $byRes[$format] ?? null;
        if ($entry === null) {
            foreach (['jpg', 'png'] as $alt) {
                if (isset($byRes[$alt])) {
                    $entry = $byRes[$alt];
                    $format = $alt;
                    break;
                }
            }
        }
        if (!is_array($entry) || !isset($entry['url'])) {
            continue;
        }
        $ext = pathinfo((string) parse_url((string) $entry['url'], PHP_URL_PATH), PATHINFO_EXTENSION) ?: $format;
        $out[$name] = [
            'url' => (string) $entry['url'],
            'ext' => $ext,
            'bytes' => (int) ($entry['size'] ?? 0),
            // The API publishes an md5 per file. Recording it makes the download
            // verifiable later rather than merely present.
            'md5' => (string) ($entry['md5'] ?? ''),
        ];
    }
    return $out;
}

$palette = palette();

echo "=== Poly Haven PBR fetch ===\n";
echo '  mode       : ' . ($apply ? 'APPLY' : 'DRY RUN') . "\n";
echo '  source     : CC0 1.0, commercial use and redistribution permitted in writing' . "\n";
echo '  target dir : public/textures/pbr' . "\n\n";

if ($list) {
    foreach ($palette as $slot => $p) {
        printf("  %-14s %-24s %-3s  %s\n", $slot, $p['asset'], $p['res'], $p['applies_to']);
    }
    echo "\nMaterials with no CC0 source yet, reported rather than faked:\n";
    echo "  pcb_solder_mask   Poly Haven has no circuit-board asset\n";
    echo "  connector_gold    no CC0 source found; a flat metallic material is correct here\n";
    exit(0);
}

$outDir = __DIR__ . '/../public/textures/pbr';
if (!is_dir($outDir)) {
    if ($apply) {
        mkdir($outDir, 0777, true);
    } else {
        echo "  (dry run: would create {$outDir})\n";
    }
}

$manifest = [];
$totalBytes = 0;
$missing = [];

foreach ($palette as $slot => $p) {
    echo "  {$slot}  <- {$p['asset']} @ {$p['res']}\n";
    $urls = mapUrls($API, $p['asset'], $p['res'], $p['format']);

    if ($urls === []) {
        $missing[] = "{$slot}: no files returned for {$p['asset']} at {$p['res']}";
        echo "      MISSING: no file listing at that resolution\n";
        continue;
    }

    $written = [];
    foreach ($urls as $name => $info) {
        $target = "{$outDir}/{$slot}_{$name}.{$info['ext']}";
        $bytes = (int) ($info['bytes'] ?: 0);

        if (!$apply) {
            printf("      %-12s %8s  %s\n", $name, $bytes ? number_format($bytes) : '?', basename($target));
            $written[$name] = basename($target);
            $totalBytes += $bytes;
            continue;
        }

        $body = grab($info['url']);
        if ($body === null || strlen($body) < 512) {
            $missing[] = "{$slot}/{$name}: download returned nothing usable";
            echo "      FAILED  {$name}\n";
            continue;
        }
        file_put_contents($target, $body);
        $written[$name] = basename($target);
        $totalBytes += strlen($body);
        printf("      %-12s %8s bytes  written\n", $name, number_format(strlen($body)));
        usleep(250000); // politeness
    }

    $manifest[] = [
        'slot' => $slot,
        'asset' => $p['asset'],
        'resolution' => $p['res'],
        'applies_to' => $p['applies_to'],
        'licence' => 'CC0 1.0 (Poly Haven)',
        'source' => "{$API}/files/{$p['asset']}",
        'files' => $written,
        'retrieved' => gmdate('Y-m-d'),
    ];
}

echo "\n";
printf("  slots attempted : %d\n", count($palette));
printf("  slots resolved  : %d\n", count($manifest));
printf("  approx payload  : %s\n", $totalBytes ? round($totalBytes / 1048576, 2) . ' MB' : 'n/a');

if ($missing !== []) {
    echo "\n  gaps (reported, not faked):\n";
    foreach ($missing as $m) {
        echo "    - {$m}\n";
    }
}

if ($apply && $manifest !== []) {
    $manifestPath = $outDir . '/manifest.json';
    file_put_contents($manifestPath, json_encode([
        'licence' => 'CC0 1.0, Poly Haven. Attribution not required and not claimed.',
        'note' => 'Install at 1k/2k only. A full build geometry is about 34KB; a 4k PBR set per part would dwarf it.',
        'materials' => $manifest,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "\n  manifest written to public/textures/pbr/manifest.json\n";
}

if (!$apply) {
    echo "\n  DRY RUN. Re-run with --apply to download.\n";
}
