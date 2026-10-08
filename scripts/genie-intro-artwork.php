<?php
/**
 * Prepares the intro overlay's upper-band artwork for the web.
 *
 * WHY THIS SCRIPT EXISTS. The Boss supplied
 * E:\Downloads\Gemini_Generated_Image_bsi732bsi732bsi7.png (1408x768, 2,029 KB,
 * 32bpp ARGB) for the top three quarters of the intro animation. At 2 MB it is far
 * too heavy for the critical path of EVERY page view - the intro overlay is on every
 * page, so this file is requested before anything else renders.
 *
 * IT IS RESIZED AND RE-ENCODED, NOT RECOLOURED. The brief was "scale the same", and
 * the artwork's colours are the brand's - the red/cyan on black is the artwork, not
 * something to be tuned. No pixel is recoloured, cropped or retouched here.
 *
 * WHY JPEG AND NOT PNG. The source is a photographic-style render with an alpha
 * channel that is fully opaque in practice; PNG stores it losslessly and costs 2 MB.
 * The overlay background behind it is solid black (bg-black on the wrapper), so the
 * alpha channel carries no information worth 2 MB.
 *
 * Usage:  php scripts/genie-intro-artwork.php
 */

$source = 'E:\\Downloads\\Gemini_Generated_Image_bsi732bsi732bsi7.png';
$target = dirname(__DIR__) . '/public/img/brand/startup-hero-top.jpg';

// Width chosen to stay sharp on a large display without carrying 2 MB: the band is
// full-viewport-width, and 1600px is already past the point where the artwork's own
// 1408px of detail is exceeded.
$targetWidth = 1600;
$quality = 85;

if (! is_file($source)) {
    fwrite(STDERR, "Source artwork not found: {$source}\n");
    exit(1);
}

$info = getimagesize($source);
if ($info === false) {
    fwrite(STDERR, "Could not read image dimensions from {$source}\n");
    exit(1);
}

[$srcWidth, $srcHeight] = $info;

if (! extension_loaded('gd')) {
    fwrite(STDERR, "GD extension is required but not loaded.\n");
    exit(1);
}

$src = imagecreatefrompng($source);
if ($src === false) {
    fwrite(STDERR, "Could not decode {$source}\n");
    exit(1);
}

// Never upscale: if the requested width exceeds the source, keep the source width.
$width = min($targetWidth, $srcWidth);
$height = (int) round($srcHeight * ($width / $srcWidth));

$dst = imagecreatetruecolor($width, $height);
// The wrapper is bg-black, so flattening onto black is invisible and removes the
// alpha channel that was costing the file size.
imagefill($dst, 0, 0, imagecolorallocate($dst, 0, 0, 0));
imagecopyresampled($dst, $src, 0, 0, 0, 0, $width, $height, $srcWidth, $srcHeight);

if (! is_dir(dirname($target))) {
    mkdir(dirname($target), 0755, true);
}
imagejpeg($dst, $target, $quality);

imagedestroy($src);
imagedestroy($dst);

clearstatcache();
$bytes = filesize($target);

printf(
    "startup-hero-top.jpg written\n  source : %s (%dx%d, %.1f KB)\n  output : %s (%dx%d, %.1f KB, q%d)\n  saved  : %.1f KB (%.0f%% smaller)\n",
    basename($source),
    $srcWidth,
    $srcHeight,
    filesize($source) / 1024,
    basename($target),
    $width,
    $height,
    $bytes / 1024,
    $quality,
    (filesize($source) - $bytes) / 1024,
    (1 - $bytes / filesize($source)) * 100,
);
