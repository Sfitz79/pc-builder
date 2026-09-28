<?php

namespace App\Services;

use App\Models\Component;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Live product imagery for catalogue components, with a local cache.
 *
 * Why this exists: the storefront renders <img :src="item.image_url"> straight
 * from the components table, and 1,975 of 2,729 rows had no image at all. The
 * old resolver (FetchComponentImages) fetched the PCPartPicker product page with
 * a plain HTTP client, which cannot get past Cloudflare, so it resolved almost
 * nothing. This service reuses PcppPriceService's Byparr transport - the same
 * Camoufox browser that gets the price table - and then pulls the image itself.
 *
 * Full-size images: PCPartPicker exposes og:image as a 256x256 thumbnail whose
 * URL carries a size token, e.g. {hash}.256p.jpg. The same asset unsuffixed
 * ({hash}.jpg) is the real product photo at 640x480, and the intermediate tokens
 * (.512p, .1024p) return 403. So we try unsuffixed first and fall back to the
 * thumbnail. That is a 2.5x linear resolution gain over what the og tag gives.
 *
 * Caching: the remote CDN URL stays in image_url (it is the canonical source and
 * matches the 754 rows already stored that way), and a copy is written under
 * public/img/components so we keep a durable local asset if the CDN ever rots.
 */
class ComponentImageService
{
    /** Where the local cache lives, relative to the public root. */
    public const CACHE_DIR = 'img/components';

    public function __construct(
        private readonly PcppPriceService $pcpp,
    ) {
    }

    /**
     * The Byparr-backed page fetcher, so callers can check it is configured
     * before starting a long run.
     */
    public function getPcpp(): PcppPriceService
    {
        return $this->pcpp;
    }

    /**
     * Pull the og:image out of already-fetched product page HTML.
     *
     * @return string|null an absolute https URL, or null when the page has no image
     */
    public function extractOgImage(string $html): ?string
    {
        $matched = preg_match(
            '/<meta\s+property=["\']og:image["\']\s+content=["\']([^"\']+)["\']/i',
            $html,
            $m
        ) === 1;

        if (! $matched) {
            // Some pages put the content attribute before the property attribute.
            $matched = preg_match(
                '/<meta\s+content=["\']([^"\']+)["\']\s+property=["\']og:image["\']/i',
                $html,
                $m
            ) === 1;
        }

        if (! $matched) {
            return null;
        }

        $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:'.$url;
        }

        // PCPP's og tag is http; we only ever fetch over https.
        if (str_starts_with($url, 'http://')) {
            $url = 'https://'.substr($url, 7);
        }

        return str_starts_with($url, 'https://') ? $url : null;
    }

    /**
     * Candidate URLs for an og:image, best first.
     *
     * For a PCPP CDN thumbnail this rewrites {hash}.256p.jpg into {hash}.jpg
     * (640x480) and keeps the original as a fallback. Non-PCPP images are
     * returned unchanged.
     *
     * @return list<string>
     */
    public function candidatesFor(string $ogImage): array
    {
        if (preg_match('#^https://cdna\.pcpartpicker\.com/.+?\.(256p|512p|1024p|350p)\.(jpg|jpeg|png|webp)$#i', $ogImage, $m) === 1) {
            $full = preg_replace('/\.(256p|512p|1024p|350p)\.(jpg|jpeg|png|webp)$/i', '.'.$m[2], $ogImage);

            return is_string($full) ? [$full, $ogImage] : [$ogImage];
        }

        return [$ogImage];
    }

    /**
     * Fetch a product page and reduce it to the best available image.
     *
     * @return array{url: string, width: int, height: int, bytes: int, source: string}
     *
     * @throws RuntimeException when the page cannot be fetched or holds no image
     */
    public function resolveUrl(string $sourceUrl): array
    {
        $og = $this->extractOgImage($this->pcpp->fetchHtml($sourceUrl));

        if ($og === null) {
            throw new RuntimeException('No og:image on the product page.');
        }

        $failures = [];

        foreach ($this->candidatesFor($og) as $candidate) {
            $download = $this->download($candidate);

            if ($download !== null) {
                return $download + ['source' => $og];
            }

            $failures[] = $candidate;
        }

        throw new RuntimeException('Image download failed for: '.implode(', ', $failures));
    }

    /**
     * Download one image URL and validate it really is an image.
     *
     * @return array{url: string, width: int, height: int, bytes: int}|null
     */
    public function download(string $url): ?array
    {
        try {
            // No withoutVerifying() here: PHP's CA chain is configured in
            // php.ini (curl.cainfo / openssl.cafile), so the certificate is
            // verified properly rather than waved through.
            $response = Http::timeout(30)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
                    // The PCPP CDN refuses hot requests without a referer.
                    'Referer' => 'https://uk.pcpartpicker.com/',
                    'Accept' => 'image/avif,image/webp,image/png,image/jpeg,*/*',
                ])
                ->get($url);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $body = $response->body();

        if (strlen($body) < 512) {
            return null;
        }

        $info = @getimagesizefromstring($body);

        if ($info === false) {
            return null;
        }

        return [
            'url' => $url,
            'width' => (int) $info[0],
            'height' => (int) $info[1],
            'bytes' => strlen($body),
        ];
    }

    /**
     * Resolve a component's image and write a local cache copy.
     *
     * @return array{url: string, width: int, height: int, bytes: int, cached: ?string}
     */
    public function resolveFor(Component $component, bool $useLocalUrl = false): array
    {
        $sourceUrl = (string) $component->source_url;

        if (trim($sourceUrl) === '') {
            throw new RuntimeException('Component has no source_url.');
        }

        $image = $this->resolveUrl($sourceUrl);

        $cached = $this->writeCache($component, $image);

        return $image + [
            'cached' => $cached,
            // When the caller wants the local file served, hand back the
            // root-relative path instead of the CDN URL.
            'url' => $useLocalUrl && $cached !== null ? '/'.$cached : $image['url'],
        ];
    }

    /**
     * Write the image bytes to public/img/components/{id}.{ext}.
     *
     * @param  array{url: string, width: int, height: int, bytes: int}  $image
     * @return string|null public-root-relative path, or null if the write failed
     */
    public function writeCache(Component $component, array $image): ?string
    {
        $extension = $this->extensionFor($image['url']);

        if ($extension === null) {
            return null;
        }

        $relative = self::CACHE_DIR.'/'.$component->id.'.'.$extension;
        $absolute = public_path($relative);

        if (! is_dir(dirname($absolute))) {
            @mkdir(dirname($absolute), 0755, true);
        }

        try {
            $body = Http::timeout(30)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
                    'Referer' => 'https://uk.pcpartpicker.com/',
                ])
                ->get($image['url'])
                ->body();
        } catch (\Throwable) {
            return null;
        }

        if (@getimagesizefromstring($body) === false) {
            return null;
        }

        if (@file_put_contents($absolute, $body) === false) {
            return null;
        }

        return $relative;
    }

    private function extensionFor(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (! is_string($path)) {
            return null;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)
            ? ($ext === 'jpeg' ? 'jpg' : $ext)
            : null;
    }
}
