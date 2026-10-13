<?php

namespace App\Services;

/**
 * Live UK component pricing from PCPartPicker UK, fetched through Byparr.
 *
 * Why Byparr: PCPartPicker sits behind a Cloudflare managed challenge, so a
 * plain HTTP client (or web fetch tool) gets an interstitial or times out. Byparr
 * runs a real browser (Camoufox, a patched Firefox) via playwright_captcha,
 * solves the challenge and returns the rendered HTML.
 *
 * The VPN note, corrected 2026-09-28: this used to be impossible while the VPN
 * was on, because a VPN address is exactly the reputation profile Cloudflare
 * challenges. That is now solved by split tunnelling - Camoufox runs off the
 * tunnel while the rest of the machine stays on it, so both can be up at once.
 * Byparr lives at C:\Users\simon\WebStormProjects\Byparr, runs on port 8191 via
 * scripts\launch-byparr.ps1, and its browser binary is
 * %LOCALAPPDATA%\camoufox\camoufox\Cache\camoufox.exe (NOT the Playwright
 * Chromium under %LOCALAPPDATA%\ms-playwright, which Byparr never launches).
 * Verify with aigen\scripts\byparr-egress-test.php: the two egress IPs must
 * differ.
 *
 * Why the markup handling is in PHP and not a DOM library: PCPartPicker changed
 * this table's markup at least once already. The selectors below target the
 * current structure (td__finalPrice / td__availability--inStock), and
 * extractRows() is pure so it can be unit tested against a saved page without
 * a browser in the loop.
 *
 * Fetcher lanes, in order of preference:
 *  1. ScraperAPI render mode (SCRAPER_API_KEY) - a cloud headless browser whose
 *     egress is not the VPN, added 2026-09-29 after the Boss supplied a key.
 *     `render=true` returns the fully-rendered page, so the Cloudflare managed
 *     challenge is solved before we ever parse. Verified live same day: the
 *     video-card listing returned real UK prices (GBP values present) and a
 *     real product page returned the exact td__finalPrice / data-merchant-tag
 *     markup this parser expects.
 *  2. Byparr (BYPARR_URL) - local Camoufox browser, works while it is running
 *     and off-VPN. This was the original lane; PCPartPicker's challenge has
 *     intermittently returned the "Unavailable" page even to it.
 *
 * When --byparr= is passed explicitly, Byparr wins (it is an explicit
 * override, used when testing Byparr itself); otherwise ScraperAPI wins when a
 * key is present, because it needs no local process and its egress is not the
 * VPN.
 */
class PcppPriceService
{
    /**
     * Rows whose live price differs from ours by more than this are reported as
     * corrections. Below it, the price is left alone: retailer prices move by a
     * few pence, and rewriting every row on every pass would churn the database
     * and the price_checked_at stamps for no accuracy gain.
     */
    public const SIGNIFICANT_DELTA = 1.0;

    public function __construct(
        private readonly ?string $byparrUrl = null,
        private readonly ?string $scraperApiKey = null,
        private readonly int $timeout = 130,
    ) {
    }

    public function configured(): bool
    {
        return $this->scraperApiKeyValue() !== null || $this->baseUrl() !== null;
    }

    /**
     * Human label for the active fetch lane, for the refresh command header.
     */
    public function laneDescription(): string
    {
        if ($this->byparrUrl !== null) {
            return 'Byparr (explicit --byparr): '.$this->baseUrl();
        }
        if ($this->scraperApiKeyValue() !== null) {
            return 'ScraperAPI render (cloud browser)';
        }

        return 'Byparr: '.($this->baseUrl() ?? 'not configured');
    }

    public function baseUrl(): ?string
    {
        $url = $this->byparrUrl ?? env('BYPARR_URL') ?? config('services.byparr.url');

        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        return rtrim(trim($url), '/');
    }

    public function scraperApiKeyValue(): ?string
    {
        $key = $this->scraperApiKey ?? env('SCRAPER_API_KEY') ?? config('services.scraperapi.key');

        if (! is_string($key) || trim($key) === '') {
            return null;
        }

        return trim($key);
    }

    /**
     * True when a source_url is a PCPartPicker UK product page we can refresh.
     */
    public function supports(string $sourceUrl): bool
    {
        return (bool) preg_match('#^https?://uk\.pcpartpicker\.com/product/#i', trim($sourceUrl));
    }

    /**
     * Fetch a product page through the active lane and return the rendered HTML.
     *
     * Lane order: explicit --byparr wins (testing Byparr itself); otherwise
     * ScraperAPI render when a key is set; otherwise Byparr.
     *
     * @throws \RuntimeException on transport or challenge failure, so the caller
     *                           can count a failure rather than silently storing
     *                           a zero price.
     */
    public function fetchHtml(string $url): string
    {
        if ($this->byparrUrl === null && $this->scraperApiKeyValue() !== null) {
            return $this->fetchHtmlViaScraperApi($url);
        }

        return $this->fetchHtmlViaByparr($url);
    }

    /**
     * Fetch through ScraperAPI render mode: a cloud headless browser solves
     * PCPartPicker's Cloudflare managed challenge and returns the fully
     * rendered page. The response body IS the HTML (render=true with no
     * output_format), so unlike Byparr there is no envelope to unwrap.
     */
    private function fetchHtmlViaScraperApi(string $url): string
    {
        $api = 'https://api.scraperapi.com/?'
            . 'api_key='.rawurlencode((string) $this->scraperApiKeyValue())
            . '&url='.rawurlencode($url)
            . '&country_code=uk&device_type=desktop&render=true';

        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => "Accept: text/html\r\n",
            'timeout' => $this->timeout,
            'ignore_errors' => true,
        ]]);

        $raw = @file_get_contents($api, false, $context);

        if ($raw === false || $raw === '') {
            throw new \RuntimeException('ScraperAPI did not respond within '.$this->timeout.'s.');
        }

        // ScraperAPI error responses are a small JSON object, not HTML.
        $trimmed = ltrim($raw);
        if (str_starts_with($trimmed, '{')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['error'])) {
                throw new \RuntimeException('ScraperAPI error: '.(string) $decoded['error']);
            }
        }

        // A challenge page can come back with HTTP 200 if it was served without
        // a redirect, so treat the interstitial text as a hard failure.
        if (preg_match('/Just a moment|Perform(?:ing)? security verification|Checking your browser|Attention Required!|PCPartPicker is unavailable/i', $raw) === 1) {
            throw new \RuntimeException('Cloudflare challenge was not solved (ScraperAPI render served a block page).');
        }

        return $raw;
    }

    /**
     * Fetch through Byparr (local Camoufox browser) and return the rendered HTML.
     *
     * @throws \RuntimeException on transport or challenge failure, so the caller
     *                           can count a failure rather than silently storing
     *                           a zero price.
     */
    private function fetchHtmlViaByparr(string $url): string
    {
        $base = $this->baseUrl();
        if ($base === null) {
            throw new \RuntimeException('Byparr is not configured (BYPARR_URL missing).');
        }

        $payload = json_encode([
            'cmd' => 'request.get',
            'url' => $url,
            'max_timeout' => 60,
        ]);

        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => $this->timeout,
            'ignore_errors' => true,
        ]]);

        $raw = @file_get_contents($base, false, $context);

        if ($raw === false || $raw === '') {
            throw new \RuntimeException('Byparr did not respond within '.$this->timeout.'s.');
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('Byparr returned a non-JSON response.');
        }

        if (($decoded['status'] ?? '') !== 'ok') {
            // Byparr is FastAPI, so transport-level failures come back as
            // {"detail": "..."} with no `status` key at all. Without surfacing
            // `detail` those read as a bare "status ?:" and hide the real cause
            // (seen 2026-09-28: "browser error: Timeout 15000ms exceeded" while
            // fetching Amazon, which is Byparr's browser budget, not our timeout).
            $reason = $decoded['message'] ?? null;
            if (! is_string($reason) || $reason === '') {
                $reason = $decoded['detail'] ?? null;
            }
            if (is_array($reason)) {
                $reason = json_encode($reason);
            }

            throw new \RuntimeException(sprintf(
                'Byparr %s: %s',
                isset($decoded['status']) ? 'status '.$decoded['status'] : 'transport error',
                is_string($reason) && $reason !== '' ? $reason : 'no reason given'
            ));
        }

        $html = (string) ($decoded['solution']['response'] ?? '');

        if ($html === '') {
            throw new \RuntimeException('Byparr returned no HTML.');
        }

        // A challenge page can come back with status ok if it was served without
        // a redirect, so treat the interstitial text as a hard failure.
        if (preg_match('/Just a moment|Perform(?:ing)? security verification|Checking your browser|Attention Required!/i', $html) === 1) {
            throw new \RuntimeException('Cloudflare challenge was not solved (is a VPN active?).');
        }

        return $html;
    }

    /**
     * Extract merchant offers from PCPartPicker product-page HTML.
     *
     * @return list<array{price: float, in_stock: bool, merchant: ?string, merchant_url: ?string, base: ?float, shipping: ?string}>
     */
    public function extractRows(string $html): array
    {
        $rows = [];

        if (preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/is', $html, $tr) !== 1 && $tr[1] === []) {
            return [];
        }

        foreach ($tr[1] as $rowHtml) {
            if (stripos($rowHtml, 'td__finalPrice') === false) {
                continue;
            }

            // The final price is the delivered price: base + shipping + tax.
            // That is the figure we advertise, so it is the one we store.
            $price = $this->firstAmount($rowHtml, '/td__finalPrice.*?(\\&pound;|\\xC2\\xA3|\\xA3)\s?([\d,]+(?:\.\d{1,2})?)/is');
            if ($price === null) {
                $price = $this->firstAmount($rowHtml, '/(\\&pound;|\\xC2\\xA3|\\xA3)\s?([\d,]+(?:\.\d{1,2})?)/is');
            }
            if ($price === null || $price <= 0) {
                continue;
            }

            $base = $this->firstAmount($rowHtml, '/td__base[^>]*>\s*(\\&pound;|\\xC2\\xA3|\\xA3)\s?([\d,]+(?:\.\d{1,2})?)/is');

            $shipping = null;
            if (preg_match('/td__shipping[^>]*>(.*?)<\/td>/is', $rowHtml, $sm) === 1) {
                $shipping = trim(html_entity_decode(strip_tags($sm[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $shipping = $shipping === '' ? null : $shipping;
            }

            $rows[] = [
                'price' => $price,
                'in_stock' => stripos($rowHtml, 'td__availability--inStock') !== false,
                // PCPP labels the retailer with data-merchant-tag on the logo
                // cell, which is a stable identifier; the affiliate destination
                // is only a fallback because it is an ad-network host.
                'merchant' => $this->merchantName($rowHtml),
                // The merchant's own destination, with any affiliate tracker
                // unwrapped. Needed to confirm the part is genuinely buyable
                // at that retailer rather than trusting PCPP's availability
                // cell, which is routinely empty (see confirmAtMerchant()).
                'merchant_url' => $this->merchantDestination($rowHtml),
                'base' => $base,
                'shipping' => $shipping,
            ];
        }

        return $rows;
    }

    /**
     * The cheapest offer we can actually buy today: lowest delivered price
     * among rows PCPartPicker marks in stock.
     *
     * @param  list<array{price: float, in_stock: bool, merchant: ?string, merchant_url: ?string, base: ?float, shipping: ?string}>  $rows
     * @return array{price: float, in_stock: bool, merchant: ?string, merchant_url: ?string, base: ?float, shipping: ?string, offers: int, in_stock_offers: int}|null
     */
    public function cheapestOffer(array $rows): ?array
    {
        if ($rows === []) {
            return null;
        }

        $buyable = array_values(array_filter($rows, fn (array $r) => $r['in_stock']));

        // No in-stock row anywhere means the part is unavailable, which is still
        // a live answer - the caller wants that to zero the stock, not to skip.
        $pool = $buyable === [] ? $rows : $buyable;

        usort($pool, fn (array $a, array $b) => $a['price'] <=> $b['price']);

        $best = $pool[0];

        return [
            'price' => $best['price'],
            'in_stock' => $buyable !== [],
            'merchant' => $best['merchant'],
            'merchant_url' => $best['merchant_url'] ?? null,
            'base' => $best['base'],
            'shipping' => $best['shipping'],
            'offers' => count($rows),
            'in_stock_offers' => count($buyable),
        ];
    }

    /**
     * The UK retailers we will take a catalogue price from.
     *
     * MEASURED 2026-10-13, and this is why the method exists. A dry run of
     * components:refresh-prices against production Neon wanted to rewrite the catalogue as:
     *
     *     Intel Core i7-13700K   GBP 349.99 -> 485.00  (+39%)  merchant: amazonuk
     *     Intel Core i5-13600K   GBP 259.99 -> 419.99  (+62%)  merchant: amazonuk
     *     TEAMGROUP T-Create 32GB DDR5-6000  460.51 -> 527.14  merchant: newegguk
     *
     * Independent check the same day, against UK retailers directly:
     *     Overclockers UK  i7-13700K  GBP 349.99 incl VAT   <- EXACTLY the current price
     *     PriceSpy UK     best price GBP 382.45 across 5 UK shops
     *                     OnBuy 382.45 | Amazon Marketplace 415.00 | Clove 438.99
     *     Box.co.uk       adjacent i5 chips GBP 152.99 - 239.49
     *
     * So the catalogue already held the BEST UK price, and the marketplace feed sits
     * 39-62 percent ABOVE every mainstream UK seller. Taking the cheapest offer overall is
     * therefore not "cheapest" in any sense that matters - it is the most expensive credible
     * source, and it would have overwritten good prices with bad ones.
     *
     * "Amazon Marketplace" is deliberately NOT the same entry as Amazon.co.uk. Marketplace
     * rows are third-party sellers on Amazon's platform, which is exactly the kind of
     * unverified seller the allowlist exists to exclude.
     */
    public const TRUSTED_RETAILERS = [
        'scan' => 'Scan',
        'scan.co.uk' => 'Scan',
        'overclockers' => 'Overclockers UK',
        'overclockers.co.uk' => 'Overclockers UK',
        'ocuk' => 'Overclockers UK',
        'clove' => 'Clove',
        'clove.co.uk' => 'Clove',
        'onbuy' => 'OnBuy',
        'onbuy.co.uk' => 'OnBuy',
        'box' => 'Box',
        'box.co.uk' => 'Box',
        'ebay' => 'eBay UK',
        'ebay.co.uk' => 'eBay UK',
        'amazon' => 'Amazon.co.uk',
        'amazon.co.uk' => 'Amazon.co.uk',
        'orbit' => 'Orbit',
        'morethanpc' => 'moreco',
        'moreco' => 'moreco',
        // PCPP's own merchant tags are run-together ("overclockerscouk", "amazonuk",
        // "onbuyco"). They are listed so a tagged row resolves the same way a named one does.
        'overclockerscouk' => 'Overclockers UK',
        'amazonuk' => 'Amazon.co.uk',
        'onbuyco' => 'OnBuy',
        'scanco' => 'Scan',
        // UK-facing sellers that also appear in PCPP's feed. "Newegg UK" is the UK storefront,
        // not the US newegg.com that inflated prices before.
        'newegg' => 'Newegg UK',
        'newegguk' => 'Newegg UK',
        'cdw' => 'CDW UK',
    ];

    /**
     * Minimum trusted offers before a computed price may be written.
     *
     * One offer is not an average, it is a single seller's number, and publishing it as
     * "the market price" is how a catalogue ends up quoting one eBay listing.
     */
    public const MIN_AVERAGE_SAMPLE = 2;

    /**
     * Reduce a merchant name or PCPP tag to a comparable key.
     *
     * The same retailer arrives spelled several ways - "Overclockers.co.uk",
     * "overclockerscouk", "Clove", "Clove Technology", "eBay UK" - and all of them must
     * resolve to one entry, or the allowlist silently stops matching and every row falls
     * through to the untrusted path. That failure is invisible: the code still returns a
     * price, just from a seller we never vetted.
     */
    public static function normaliseMerchant(?string $merchant): string
    {
        $m = strtolower(trim((string) $merchant));
        $m = preg_replace('#^www\.#', '', $m) ?? $m;
        $m = preg_replace('#\.(co\.uk|com|uk|net)$#', '', $m) ?? $m;
        $m = preg_replace('#\s+(technology|ltd|limited|uk)$#', '', $m) ?? $m;
        $m = preg_replace('#[^a-z0-9]+#', '', $m) ?? $m;

        return trim($m);
    }

    /**
     * Is this seller one we will quote a catalogue price from?
     *
     * Static on purpose: this is a pure lookup over TRUSTED_RETAILERS, and the contract test
     * (scripts/test-trusted-retailers.php) asserts it directly without booting a service.
     */
    public static function isTrustedMerchant(?string $merchant): bool
    {
        $key = self::normaliseMerchant($merchant);
        if ($key === '') {
            return false;
        }

        // Amazon Marketplace is a THIRD-PARTY seller on Amazon's platform, not Amazon UK.
        // It must never be treated as the same trusted source.
        if (str_contains($key, 'marketplace')) {
            return false;
        }

        return array_key_exists($key, self::TRUSTED_RETAILERS);
    }

    /**
     * The cheapest offer from a TRUSTED UK retailer, falling back to an untrusted one
     * rather than returning nothing.
     *
     * The fallback is flagged, because a price we could not attribute to a mainstream UK
     * seller must not be presented as if it could.
     *
     * @param  list<array{price: float, in_stock: bool, merchant: ?string, merchant_url: ?string, base: ?float, shipping: ?string}>  $rows
     * @return array<string, mixed>|null
     */
    public function cheapestTrustedOffer(array $rows): ?array
    {
        if ($rows === []) {
            return null;
        }

        $trusted = array_values(array_filter(
            $rows,
            fn (array $r) => self::isTrustedMerchant($r['merchant'] ?? null)
        ));

        $pool = $trusted !== [] ? $trusted : $rows;

        usort($pool, fn (array $a, array $b) => $a['price'] <=> $b['price']);

        // Prefer a buyable row, exactly as cheapestOffer() does, so the two agree.
        $buyable = array_values(array_filter($pool, fn (array $r) => $r['in_stock']));
        if ($buyable !== []) {
            $pool = $buyable;
        }

        $best = $pool[0];

        return [
            'price' => $best['price'],
            'in_stock' => $buyable !== [],
            'merchant' => $best['merchant'],
            'merchant_url' => $best['merchant_url'] ?? null,
            'base' => $best['base'],
            'shipping' => $best['shipping'],
            'offers' => count($rows),
            'in_stock_offers' => count($buyable),
            'trusted_found' => $trusted !== [],
            'trusted_note' => $trusted === []
                ? 'No trusted UK retailer offered this part; the price is from an unverified seller and must not be advertised as a mainstream UK price.'
                : null,
        ];
    }

    /**
     * The price we publish: the mean of every TRUSTED, IN-STOCK UK offer, rounded UP.
     *
     * Boss directive 2026-10-13: average across the popular UK retailers, then round up to
     * the nearest pound, and require at least two trusted offers before writing anything.
     *
     * Rounding is UP because the published number is a promise we have to be able to keep.
     * Rounding down would advertise a price that the average cannot always fund.
     *
     * Out-of-stock offers are excluded from the average: they are not a price anyone can
     * buy today. They are still reported in `out_of_stock_trusted` so the caller can tell
     * "no longer sold in the UK" from "sold, but the crawl failed".
     *
     * @param  list<array{price: float, in_stock: bool, merchant: ?string, merchant_url: ?string, base: ?float, shipping: ?string}>  $rows
     * @return array<string, mixed>
     */
    public function averageTrustedPrice(array $rows): array
    {
        $trusted = array_values(array_filter(
            $rows,
            fn (array $r) => self::isTrustedMerchant($r['merchant'] ?? null)
        ));

        $buyable = array_values(array_filter($trusted, fn (array $r) => $r['in_stock']));

        $base = [
            'price' => null,
            'sample_size' => count($buyable),
            'trusted_offers' => count($trusted),
            'out_of_stock_trusted' => count($trusted) - count($buyable),
            'min' => null,
            'max' => null,
            'median' => null,
            // An average belongs to nobody, so it must not be attributed to a merchant.
            'merchant' => null,
            'merchant_url' => null,
            'base' => null,
            'shipping' => null,
            'note' => null,
        ];

        if ($buyable === []) {
            $base['note'] = $trusted === []
                ? 'No trusted UK retailer offered this part.'
                : 'Every trusted UK offer is out of stock.';

            return $base;
        }

        $prices = array_map(fn (array $r) => (float) $r['price'], $buyable);
        sort($prices);

        $count = count($prices);
        $sum = array_sum($prices);
        $mean = $sum / $count;

        $median = $count % 2 === 1
            ? $prices[intdiv($count, 2)]
            : ($prices[intdiv($count, 2) - 1] + $prices[intdiv($count, 2)]) / 2;

        // Round UP to the nearest whole pound. Round to 2dp first so 100.499999 from
        // floating-point division cannot ceil to 101 when the true mean is 100.49.
        $rounded = ceil(round($mean, 2));

        $base['price'] = $rounded;
        $base['min'] = $prices[0];
        $base['max'] = $prices[$count - 1];
        $base['median'] = round($median, 2);
        $base['note'] = sprintf(
            'Averaged across %d trusted UK retailer%s (min GBP %s, max GBP %s), rounded up to the nearest pound.',
            $count,
            $count === 1 ? '' : 's',
            number_format($prices[0], 2),
            number_format($prices[$count - 1], 2)
        );

        return $base;
    }

    /**
     * The merchant's own destination page for an offer row, with any PCPP
     * affiliate click tracker removed.
     *
     * PCPP does not link straight to the retailer. Retailers on an affiliate
     * network are linked through a click tracker that carries the real
     * destination in a query parameter (`ued` on Awin, verified against live
     * markup 2026-09-28). Fetching the tracker URL itself would return the
     * network's page, not the product page, so stock could never be confirmed.
     * Unwrap first, then confirm.
     */
    private function merchantDestination(string $rowHtml): ?string
    {
        $candidates = [];

        // The price cell and the Buy button both link to the same destination;
        // take whichever matches first so a future markup change that drops one
        // of them still works.
        if (preg_match('/td__finalPrice.*?<a[^>]+href="([^"]+)"/is', $rowHtml, $a) === 1) {
            $candidates[] = $a[1];
        }
        if (preg_match('/td__logo[^>]*>\s*<a[^>]+href="([^"]+)"/is', $rowHtml, $a) === 1) {
            $candidates[] = $a[1];
        }

        foreach ($candidates as $candidate) {
            $url = $this->unwrapAffiliate(html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Pull the retailer's real product URL out of a PCPP affiliate click URL.
     */
    private function unwrapAffiliate(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            return null;
        }

        // Already the retailer's own domain (amazon.co.uk, scan.co.uk, ...).
        if (preg_match('/awin|tradedoubler|zanox|shareasale|affiliate|cread\.php/i', $host.' '.$url) !== 1) {
            return $this->isHttpUrl($url) ? $url : null;
        }

        $query = (string) parse_url($url, PHP_URL_QUERY);
        if ($query === '') {
            return null;
        }
        parse_str($query, $params);

        foreach (['ued', 'u', 'url', 'dest', 'destination', 'target'] as $key) {
            if (! isset($params[$key]) || ! is_string($params[$key])) {
                continue;
            }
            // Some networks double-encode, so try the decoded form too.
            foreach ([$params[$key], rawurldecode($params[$key])] as $maybe) {
                if ($this->isHttpUrl($maybe)) {
                    return $maybe;
                }
            }
        }

        return null;
    }

    private function isHttpUrl(string $url): bool
    {
        return preg_match('#^https?://[^\s/]+\.[^\s/]+#i', trim($url)) === 1;
    }

    /**
     * Confirm a part is genuinely buyable at the retailer, rather than trusting
     * PCPP's availability cell.
     *
     * Observed 2026-09-28 on a live page: for the AMD Ryzen 7 9800X3D the Amazon
     * row's availability cell was empty (`<td class="td__availability ">`) while
     * the Scan row read `td__availability--inStock`. PCPP's flag is sometimes
     * simply absent, so "PCPP says in stock" is not proof the retailer will sell
     * it to us today.
     *
     * Deliberately fail-safe: a page we cannot read as in stock counts as NOT
     * confirmed. A wrong claim costs a failed order and the 5-star reputation; a
     * false negative only leaves the component unpublishable until the next pass.
     *
     * @return array{confirmed: bool, reason: string}
     */
    public function confirmAtMerchant(?string $merchantUrl, ?string $expectedMerchant = null): array
    {
        if ($merchantUrl === null || ! $this->isHttpUrl($merchantUrl)) {
            return ['confirmed' => false, 'reason' => 'no usable merchant destination URL'];
        }

        try {
            $html = $this->fetchHtml($merchantUrl);
        } catch (\RuntimeException $e) {
            return ['confirmed' => false, 'reason' => 'merchant page unreadable: '.$e->getMessage()];
        }

        // Amazon serves a 200 "Click the button below to continue shopping"
        // interstitial to automated clients, verified 2026-09-28: 3519 bytes
        // titled "Amazon.co.uk", carrying only a logo and a continue button,
        // where a real product page is ~2.5MB. It is HTTP 200, so a status check
        // would pass it, and it has no stock language either way - which means it
        // must be rejected as unreadable rather than counted as an absence of
        // out-of-stock wording.
        if (preg_match('/Click the button below to continue shopping|api-services-support@amazon\.com/i', $html) === 1) {
            return ['confirmed' => false, 'reason' => 'retailer served an anti-bot interstitial, not a product page'];
        }

        // A bounce to a captcha, a 404, an age gate or an anti-bot interstitial
        // has confirmed nothing, even when the status was a clean 200.
        if (preg_match('/Just a moment|Perform(?:ing)? security verification|Page Not Found|Enter your (?:date of birth|age)|Are you over 18|Click the button below to continue shopping|api-services-support@amazon\.com|automated access to .* data/i', $html) === 1) {
            return ['confirmed' => false, 'reason' => 'merchant page did not resolve to a product page'];
        }

        $flat = $this->normaliseText($html);

        // Out-of-stock language wins over everything: templates often render a
        // "notify me" link even for saleable items, never the reverse.
        if (preg_match('/out of stock|currently unavailable|sold out|no longer available|temporarily unavailable|notify me (?:when|if)|back in stock/i', $flat) === 1) {
            return ['confirmed' => false, 'reason' => 'merchant page reports out of stock'];
        }

        foreach ([
            '/add to (?:cart|basket|bag)\b/i',
            '/available for (?:immediate )?delivery/i',
            '/\bin stock\b/i',
            '/\badd to order\b/i',
        ] as $pattern) {
            if (preg_match($pattern, $flat) === 1) {
                return [
                    'confirmed' => true,
                    'reason' => 'merchant page shows a buy signal'.($expectedMerchant !== null ? ' at '.$expectedMerchant : ''),
                ];
            }
        }

        return ['confirmed' => false, 'reason' => 'merchant page shows no buy signal'];
    }

    /**
     * Fetch one product page and reduce it to the offer we should be selling at.
     *
     * @return array{price: float, in_stock: bool, merchant: ?string, merchant_url: ?string, base: ?float, shipping: ?string, offers: int, in_stock_offers: int}
     */
    public function cheapestOfferFor(string $sourceUrl): array
    {
        $offer = $this->cheapestOffer($this->extractRows($this->fetchHtml($sourceUrl)));

        if ($offer === null) {
            throw new \RuntimeException('No merchant offers found on the page.');
        }

        return $offer;
    }

    /**
     * The cheapest offer we can actually buy today, confirmed at the retailer.
     *
     * Amazon UK is both the cheapest offer on a large share of PCPP rows and the
     * one merchant we cannot reliably read: measured 2026-09-28, the same URL
     * returned 2.5MB once, 3519 bytes once and 0 bytes once across repeated
     * Byparr calls, and short timeouts surfaced as FastAPI
     * "browser error: Timeout 15000ms exceeded". So a single-row design that
     * only ever looks at the cheapest offer would fail most of the catalogue for
     * reasons that have nothing to do with the part.
     *
     * Instead walk PCPP's in-stock offers cheapest-first and return the first one
     * whose own product page confirms a buy signal. This keeps the fail-safe
     * property (nothing is ever advertised on an unverified page) while still
     * producing a price and a source URL for rows where a second merchant is
     * readable.
     *
     * @param  list<array{price: float, in_stock: bool, merchant: ?string, merchant_url: ?string, base: ?float, shipping: ?string}>  $rows
     * @return array{price: float, in_stock: bool, merchant: ?string, merchant_url: ?string, base: ?float, shipping: ?string, offers: int, in_stock_offers: int, confirmed_reason: string, skipped: list<array{merchant: ?string, price: float, reason: string}>}
     */
    public function cheapestConfirmedOffer(array $rows): array
    {
        $buyable = array_values(array_filter($rows, fn (array $r) => $r['in_stock']));
        $pool = $buyable === [] ? $rows : $buyable;

        usort($pool, fn (array $a, array $b) => $a['price'] <=> $b['price']);

        $skipped = [];

        foreach ($pool as $row) {
            $check = $this->confirmAtMerchant($row['merchant_url'] ?? null, $row['merchant'] ?? null);

            if (! $check['confirmed']) {
                $skipped[] = [
                    'merchant' => $row['merchant'] ?? null,
                    'price' => $row['price'],
                    'reason' => $check['reason'],
                ];
                continue;
            }

            return [
                'price' => $row['price'],
                'in_stock' => $buyable !== [],
                'merchant' => $row['merchant'] ?? null,
                'merchant_url' => $row['merchant_url'] ?? null,
                'base' => $row['base'] ?? null,
                'shipping' => $row['shipping'] ?? null,
                'offers' => count($rows),
                'in_stock_offers' => count($buyable),
                'confirmed_reason' => $check['reason'],
                'skipped' => $skipped,
            ];
        }

        // Nothing on this page could be confirmed. That is a real answer, not an
        // error: the caller must leave the row unpublishable rather than guess.
        $reasons = array_map(
            fn (array $s) => ($s['merchant'] ?? 'unknown').': '.$s['reason'],
            $skipped
        );

        throw new \RuntimeException(
            'No merchant could be confirmed buyable. Tried '.count($skipped).' offer(s): '.implode('; ', $reasons)
        );
    }

    /**
     * Fetch a product page and return the cheapest CONFIRMED offer.
     *
     * @return array{price: float, in_stock: bool, merchant: ?string, merchant_url: ?string, base: ?float, shipping: ?string, offers: int, in_stock_offers: int, confirmed_reason: string, skipped: list<array{merchant: ?string, price: float, reason: string}>}
     */
    public function cheapestConfirmedOfferFor(string $sourceUrl): array
    {
        return $this->cheapestConfirmedOffer($this->extractRows($this->fetchHtml($sourceUrl)));
    }

    /**
     * Collapse markup to a single whitespace-normalised line so the stock
     * phrases can be matched without tripping over tags or newlines.
     */
    private function normaliseText(string $html): string
    {
        // Tags become SPACES, not nothing. Plain strip_tags() glues adjacent
        // text nodes together, so <button>Add to basket</button><span>In stock
        // becomes "Add to basketIn stock" - the phrase "add to basket" then has
        // no word boundary after it and a correct positive check silently fails.
        // Script and style bodies are dropped first so their contents cannot
        // contribute a false "in stock".
        $html = (string) preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html);
        // Replace every remaining tag with a space BEFORE stripping, so the
        // strip cannot weld neighbouring words together.
        $html = (string) preg_replace('/<[^>]*>/', ' ', $html);
        $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    private function firstAmount(string $html, string $pattern): ?float
    {
        if (preg_match($pattern, $html, $m) !== 1) {
            return null;
        }

        return (float) str_replace(',', '', $m[2]);
    }

    private function merchantName(string $rowHtml): ?string
    {
        if (preg_match('/data-merchant-tag="([^"]+)"/i', $rowHtml, $m) === 1) {
            return $m[1];
        }

        if (preg_match('/td__finalPrice.*?<a[^>]+href="([^"]+)"/is', $rowHtml, $a) === 1) {
            $url = html_entity_decode($a[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('#^https?://([^/]+)#i', $url, $h) === 1) {
                return $h[1];
            }
        }

        return null;
    }
}
