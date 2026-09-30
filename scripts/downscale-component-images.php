<?php

/**
 * Downscale the component image cache to a storefront-appropriate size.
 *
 * WHY THIS EXISTS (measured 2026-09-28)
 * --------------------------------------
 * The cache held 3,290 files / 234.7 MB and vercel-php bundled all of it into the
 * Vercel function, taking api/index.php to 443.54 MB against a 250 MB limit.
 *
 * The images were the problem, not the architecture: 2,226 files (210.9 MB, 90% of
 * the cache) were larger than 600px, including 1,200px and 1,600px originals. A
 * storefront thumbnail rendered at 2x needs at most 600px, so those bytes were being
 * shipped to every visitor for no visual benefit.
 *
 * Behaviour
 * ---------
 * - Images already within the 600px box are left completely alone.
 * - Images above the box are re-encoded to JPEG q82, preserving aspect ratio.
 * - Aspect ratio is preserved; nothing is cropped or stretched.
 * - A no-op run is detected and reported as such, so a repeat run is visibly safe.
 *
 * This only ever SHRINKS files. It never enlarges, never re-encodes an already
 * compliant image, and never touches the placeholders.
 *
 * Usage:  php scripts/downscale-component-images.php [--max=600] [--quality=82] [--dry-run]
 */

// Batch image work. The default 128M is not enough to decode a 3000x3000 source
// (~36 MB raw) alongside the resample target, and PHP aborted mid-run without it.
ini_set('memory_limit', '1024M');

$dir = __DIR__ . '/../public/img/components';
$max = 600;
$quality = 82;

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--max=(\d+)$/', $arg, $m)) {
        $max = max(64, (int) $m[1]);
    } elseif (preg_match('/^--quality=(\d+)$/', $arg, $m)) {
        $quality = min(95, max(40, (int) $m[1]));
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    }
}
$dryRun = $dryRun ?? false;

if (!is_dir($dir)) {
    fwrite(STDERR, "cache directory not found: $dir\n");
    exit(1);
}

$files = glob($dir . '/*');
$shrunk = 0;
$alreadyOk = 0;
$failed = 0;
$before = 0;
$after = 0;
$skippedNonImage = 0;
$failedNames = [];

foreach ($files as $path) {
    if (!is_file($path)) {
        continue;
    }

    $sizeBefore = filesize($path);
    $before += $sizeBefore;

    $info = @getimagesize($path);
    if ($info === false) {
        // Not a readable raster image. Leave it exactly as it is.
        $skippedNonImage++;
        $after += $sizeBefore;
        continue;
    }

    [$w, $h] = $info;

    // Already compliant: do not touch the bytes at all.
    if ($w <= $max && $h <= $max) {
        $alreadyOk++;
        $after += $sizeBefore;
        continue;
    }

    $img = @imagecreatefromjpeg($path);
    if ($img === false) {
        $img = @imagecreatefromstring((string) file_get_contents($path));
    }
    if ($img === false) {
        $failed++;
        $failedNames[] = basename($path) . ' (unreadable)';
        $after += $sizeBefore;
        continue;
    }

    // Scale down into a 600px box, preserving aspect ratio. Never scale up.
    $ratio = min($max / $w, $max / $h, 1.0);
    $newW = max(1, (int) round($w * $ratio));
    $newH = max(1, (int) round($h * $ratio));

    $dst = imagecreatetruecolor($newW, $newH);

    // Preserve transparency (PNG-sourced entries) by using a transparent canvas.
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefilledrectangle($dst, 0, 0, $newW, $newH, $transparent);
    imagealphablending($dst, true);

    // High-quality resample rather than a cheap nearest-neighbour shrink.
    imagecopyresampled($dst, $img, 0, 0, 0, 0, $newW, $newH, $w, $h);
    imagedestroy($img);

    ob_start();
    $ok = imagejpeg($dst, null, $quality);
    $encoded = (string) ob_get_clean();
    imagedestroy($dst);

    if (!$ok || $encoded === '') {
        $failed++;
        $failedNames[] = basename($path) . ' (encode failed)';
        $after += $sizeBefore;
        continue;
    }

    // Dimension compliance WINS over byte count. An earlier version of this script
    // kept the original whenever the re-encode was not smaller in bytes, which left
    // 114 files over the 600px box (640x480 sources that were already highly
    // optimised at ~11 KB) while still reporting "failed: 0". That is a silent
    // fallback: the cache looked compliant and was not. For an oversized image we
    // always write the downscale, even if the result is a byte or two larger.
    $sizeAfter = strlen($encoded);
    $after += $sizeAfter;
    $shrunk++;

    printf(
        "  %-18s %5dx%-5d -> %5dx%-5d  %7.1f KB -> %6.1f KB  (-%d%%)\n",
        basename($path),
        $w,
        $h,
        $newW,
        $newH,
        $sizeBefore / 1024,
        $sizeAfter / 1024,
        (int) round(100 - ($sizeAfter / $sizeBefore * 100))
    );

    if (!$dryRun) {
        file_put_contents($path, $encoded);
    }

    // Release the encoded buffer before the next iteration so peak memory stays
    // flat across 3,290 files rather than compounding.
    unset($encoded, $dst);
}

$mb = static fn (int $b): string => round($b / 1048576, 1);

echo "\n";
echo $dryRun ? "DRY RUN - no files written\n" : "Done.\n";
printf(
    "  cache: %d files, %s MB -> %s MB (%s%% smaller)\n",
    count($files),
    $mb($before),
    $mb($after),
    $before > 0 ? round(100 - ($after / $before * 100)) : 0
);
printf("  already within %dpx (untouched): %d\n", $max, $alreadyOk);
printf("  downscales: %d\n", $shrunk);
printf("  failed: %d   non-image/other (untouched): %d\n", $failed, $skippedNonImage);

if ($failed > 0) {
    echo "\n  failures (left untouched):\n";
    foreach (array_slice($failedNames, 0, 20) as $n) {
        echo "    - $n\n";
    }
}

// A no-op run must be visible, not silent (a silent no-op is indistinguishable
// from "it ran and changed nothing useful" and hides a wrong --max).
if ($shrunk === 0 && $failed === 0) {
    echo "\n  NOTE: no file needed shrinking. The cache is already within the "
        . "{$max}px budget - a repeat run is a safe no-op.\n";
}
