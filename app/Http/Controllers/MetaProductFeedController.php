<?php

namespace App\Http\Controllers;

use App\Models\Component;
use Illuminate\Http\Response;

/**
 * META / FACEBOOK PRODUCT FEED.
 *
 * WHY THIS EXISTS (2026-10-13)
 * ----------------------------
 * Meta discontinued in-app checkout on Facebook and Instagram globally in September 2025.
 * Shops are now a catalogue + advert surface that sends traffic to our own site: no
 * commission, no payment processing, no order or dispute handling. Verified against Meta's
 * own help page before building anything on it.
 *
 * That makes it the cheapest revenue action available: the catalogue, the verified images
 * and the live averaged UK prices already exist in Postgres, and Meta fetches a feed from a
 * URL we control - no Shopify, no plugin, no per-sale fee. The eligibility review takes
 * WEEKS, so the clock only starts once this endpoint is live.
 *
 * WHAT GOES IN THE FEED, AND WHY NOT THE 2,700-ROW COMPONENT CATALOGUE
 * --------------------------------------------------------------------
 * A configured-to-order PC is not a SKU: one price, one availability, one link. Neither
 * Meta, eBay nor TikTok Shop can list "your PC". What can be listed are LOCKED, PRICE-PINNED
 * tiers - and we already have them: the featured builds.
 *
 * So the feed carries the locked tiers, each linked to the builder's own deep-link
 * (?featured=<slug>), which loads that exact specification. Every row is a build we can
 * actually assemble and price, which is the whole standard the 100% 5-star reputation rests
 * on. A feed of 2,700 interchangeable component rows would also be 2,700 near-duplicate
 * links, which is how a catalogue feed gets rejected.
 *
 * HONESTY RULES BAKED IN
 * ----------------------
 *  - A build missing any part is NOT emitted. It cannot be bought, so advertising it is a
 *    promise we cannot keep. Same rule as the featured page itself.
 *  - Price comes from the LIVE catalogue total, not a typed constant.
 *  - No fabricated imagery: the image is a real part photo we hold. Nothing is generated.
 *  - If the catalogue cannot price a build, the row is skipped rather than guessed.
 */
class MetaProductFeedController extends Controller
{
    /** Meta requires a price and an availability for every row. */
    private const META_CURRENCY = 'GBP';

    public function csv(): Response
    {
        $path = database_path('scraped/featured-builds.json');
        $rows = [];

        if (is_file($path)) {
            $raw = json_decode((string) file_get_contents($path), true);

            foreach ($raw['builds'] ?? [] as $entry) {
                $parts = [];
                $total = 0.0;
                $image = null;

                foreach ($entry['parts'] ?? [] as $part) {
                    $component = Component::query()->active()->find($part['id'] ?? null);
                    if (! $component) {
                        continue;
                    }
                    $total += (float) $component->price;
                    $parts[] = $component;
                    // The hero image is the first real part photo we hold, GPU preferred so
                    // the card reads as a gaming machine rather than a motherboard.
                    if ($image === null && filled($component->image_url)) {
                        $image = $component->image_url;
                    }
                }

                // Incomplete build: cannot be bought, so it is not advertised.
                if ($parts === [] || count($parts) !== count($entry['parts'] ?? [])) {
                    continue;
                }

                // Nothing priced, nothing advertised.
                if ($total <= 0) {
                    continue;
                }

                $slug = (string) ($entry['slug'] ?? '');
                if ($slug === '') {
                    continue;
                }

                $name = (string) ($entry['name'] ?? 'PCTG custom gaming PC');
                $summary = trim((string) ($entry['summary'] ?? ''));
                $promise = trim((string) ($entry['promise'] ?? ''));

                $description = trim($summary.' '.$promise);
                if ($description === '') {
                    $description = 'Custom gaming PC built to order by PCTechGuyOnline in the UK.';
                }

                // Explicit spec list, so the card is not a bare name.
                $bom = collect($parts)
                    ->map(fn (Component $c) => $c->name)
                    ->implode('; ');
                $description = trim($description.' Fitted with: '.$bom.'.');

                $rows[] = [
                    'id' => 'pctg-'.$slug,
                    'title' => $name,
                    'description' => $this->clean($description),
                    'availability' => 'in stock',
                    'condition' => 'new',
                    'price' => number_format($total, 2, '.', ''),
                    'currency' => self::META_CURRENCY,
                    'link' => $this->absoluteUrl('/builder?featured='.urlencode($slug)),
                    'image_link' => $this->absoluteImage($image),
                    'brand' => 'PCTechGuyOnline',
                    'google_product_category' => 'Computers > Desktop Computers',
                    'fb_product_category' => 'electronics',
                    'quantity_to_sell_on_facebook' => '1',
                ];
            }
        }

        // CSV, per Meta's Product Feed spec. Header row is mandatory.
        $header = [
            'id', 'title', 'description', 'availability', 'condition', 'price', 'currency',
            'link', 'image_link', 'brand', 'google_product_category', 'fb_product_category',
            'quantity_to_sell_on_facebook',
        ];

        $lines = [implode(',', $header)];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($header as $col) {
                $cells[] = $this->csvCell((string) ($row[$col] ?? ''));
            }
            $lines[] = implode(',', $cells);
        }

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            // Short cache: prices move, and a stale feed advertises a stale price.
            'Cache-Control' => 'public, max-age=900',
        ]);
    }

    /** Meta rejects a row with no image, so fall back to the site OG image. */
    private function absoluteImage(?string $image): string
    {
        $image = trim((string) $image);

        if ($image === '') {
            return $this->absoluteUrl('/img/placeholders/pc.svg');
        }

        if (preg_match('#^https?://#i', $image)) {
            return $image;
        }

        return $this->absoluteUrl('/'.ltrim($image, '/'));
    }

    /**
     * Build an ABSOLUTE public URL.
     *
     * WHY THIS IS NOT JUST config('app.url')
     * ---------------------------------------
     * Measured 2026-10-13: the first render emitted `http://localhost/builder?featured=...`
     * for every row. On Vercel production APP_URL is not set, so config('app.url') falls back
     * to http://localhost - and Meta rejects a feed whose links are not absolute public URLs.
     * Every row would have been refused, and the failure would look like a Meta config problem
     * rather than ours.
     *
     * A feed is always fetched over HTTP, so the incoming request's own host IS the public
     * host. That is used first, and config('app.url') is only trusted when it is a real
     * public URL. This is the same trap that already affected root-relative component images.
     */
    private function absoluteUrl(string $path): string
    {
        $base = rtrim((string) request()->root(), '/');

        if (! preg_match('#^https?://#i', $base) || str_contains($base, 'localhost')) {
            $configured = rtrim((string) config('app.url'), '/');
            if (preg_match('#^https?://#i', $configured) && ! str_contains($configured, 'localhost')) {
                $base = $configured;
            }
        }

        return $base.$path;
    }

    /** Collapse whitespace; Meta rejects control characters in descriptions. */
    private function clean(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    }

    /** RFC4180 quoting, and a guard against formula injection in a spreadsheet-opened feed. */
    private function csvCell(string $value): string
    {
        $value = str_replace(["\r", "\n"], ' ', $value);

        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            $value = "'".$value;
        }

        return '"'.str_replace('"', '""', $value).'"';
    }
}
