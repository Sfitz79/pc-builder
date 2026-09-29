<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Awin product data feed access (fetch + parse). Read-only by design: nothing
 * in this service writes to the catalogue. Turning a parsed feed row into a
 * stamped component is a deliberate, separate step (see the prices:ingest-feed
 * command), because a feed is evidence, not a price change.
 *
 * Endpoints were taken from Awin's developer docs on 2026-09-29, NOT from
 * memory (a client written from memory got a Byparr call wrong before):
 *
 *   Feed list  : {feed_base}/datafeed/list/apikey/{feedApiKey}
 *   Feed (CSV) : {feed_base}/datafeed/download/apikey/{feedApiKey}/columns/{cols}/format/csv/delimiter/%2C/compression/gzip/[mid|cid|bid/{ids}/]
 *   Enhanced   : {api_base}/publishers/{publisherId}/awinfeeds/download/{advertiserId}-retail-{locale}.jsonl  (Bearer)
 *
 * The FEED api key is a different credential from the Publisher API key, and
 * neither is present in this repo (owner-held). Every network method fails
 * loudly when unconfigured rather than returning an empty "success".
 */
class AwinFeedService
{
    /**
     * The columns we want by default. Chosen so a download carries everything
     * needed to (a) identify a product and (b) prove it is buyable today:
     * identity (name/ean/gtin/mpn/brand), price (search_price), stock
     * (in_stock/stock_quantity/stock_status), and a link (deep_link).
     *
     * Column names verified against Awin's "Hosting and column descriptions"
     * and "Feed Column Descriptions" docs, 2026-09-29.
     *
     * @var list<string>
     */
    public const DEFAULT_COLUMNS = [
        'aw_product_id',
        'product_name',
        'merchant_id',
        'merchant_name',
        'aw_deep_link',
        'merchant_deep_link',
        'search_price',
        'store_price',
        'in_stock',
        'stock_quantity',
        'stock_status',
        'ean',
        'upc',
        'product_GTIN',
        'mpn',
        'brand_name',
        'rrp_price',
        'currency',
    ];

    public function __construct(
        private readonly ?string $feedApiKey = null,
        private readonly ?string $publisherId = null,
        private readonly ?string $apiToken = null,
        private readonly ?string $locale = null,
        private readonly int $timeout = 300,
        private readonly ?Client $http = null,
    ) {
    }

    // ---------------------------------------------------------------------
    // Configuration
    // ---------------------------------------------------------------------

    public function feedApiKey(): ?string
    {
        return $this->clean($this->feedApiKey ?? config('services.awin.feed_api_key'));
    }

    public function publisherId(): ?string
    {
        return $this->clean($this->publisherId ?? config('services.awin.publisher_id'));
    }

    public function apiToken(): ?string
    {
        return $this->clean($this->apiToken ?? config('services.awin.api_token'));
    }

    public function locale(): string
    {
        return $this->clean($this->locale ?? config('services.awin.locale')) ?? 'en_GB';
    }

    public function timeout(): int
    {
        return $this->timeout > 0 ? $this->timeout : 300;
    }

    /** The legacy CSV feed needs only the feed key. */
    public function configured(): bool
    {
        return $this->feedApiKey() !== null;
    }

    /** The enhanced (JSONL) feed needs a publisher id and a Bearer token. */
    public function apiConfigured(): bool
    {
        return $this->publisherId() !== null && $this->apiToken() !== null;
    }

    // ---------------------------------------------------------------------
    // URLs
    // ---------------------------------------------------------------------

    public function feedListUrl(): string
    {
        return $this->feedBase().'/datafeed/list/apikey/'.rawurlencode($this->requireFeedKey());
    }

    /**
     * Build a legacy feed download URL.
     *
     * @param  array{columns?: array<int,string>|string|null, compression?: string, delimiter?: string, mid?: array<int,string|int>|string|int, cid?: array<int,string|int>|string|int, bid?: array<int,string|int>|string|int}  $options
     */
    public function downloadUrl(array $options = []): string
    {
        $key = $this->requireFeedKey();

        $columns = $options['columns'] ?? null;
        if ($columns === null) {
            $columns = self::DEFAULT_COLUMNS;
        }
        $columns = array_values(array_filter(array_map(
            fn ($c) => trim((string) $c),
            (array) $columns
        )));

        $compression = (string) ($options['compression'] ?? 'gzip');
        $delimiter = (string) ($options['delimiter'] ?? ',');

        $url = $this->feedBase().'/datafeed/download/apikey/'.rawurlencode($key);

        // Awin expects the column list as literal comma-separated path segments.
        if ($columns !== []) {
            $url .= '/columns/'.implode(',', $columns);
        }

        $url .= '/format/csv';
        $url .= '/delimiter/'.rawurlencode($delimiter);

        if ($compression !== 'none') {
            $url .= '/compression/'.rawurlencode($compression);
        }

        // Optional filters: mid = merchant ids, cid = category ids, bid = brand ids.
        //
        // The id list is a COMMA-SEPARATED list of path values, and the commas
        // are literal, not percent-encoded. Awin's own documented examples show
        // `/mid/957,513/`, so encoding the commas as %2C would produce a URL the
        // service does not accept - a real bug caught by the test that asserts
        // the documented shape.
        foreach (['mid', 'cid', 'bid'] as $filter) {
            if (! isset($options[$filter]) || $options[$filter] === '' || $options[$filter] === []) {
                continue;
            }
            $ids = is_array($options[$filter])
                ? implode(',', array_map('strval', $options[$filter]))
                : (string) $options[$filter];
            $url .= '/'.$filter.'/'.$ids;
        }

        return $url;
    }

    /**
     * Enhanced (Google-format, JSONL) feed URL for one advertiser. Documented
     * endpoint; downloading it needs the Bearer token, which this service does
     * not attach on the legacy path so the two cannot be confused.
     */
    public function enhancedDownloadUrl(string $advertiserId, string $vertical = 'retail', ?string $locale = null): string
    {
        $pid = $this->publisherId();
        if ($pid === null) {
            throw new \RuntimeException('Awin publisher id is not configured (AWIN_PUBLISHER_ID).');
        }

        return $this->apiBase().'/publishers/'.rawurlencode($pid)
            .'/awinfeeds/download/'.rawurlencode($advertiserId.'-'.$vertical.'-'.($locale ?? $this->locale()))
            .'.jsonl';
    }

    // ---------------------------------------------------------------------
    // Fetch
    // ---------------------------------------------------------------------

    /** Download the list of feeds this publisher can access. */
    public function feedList(): array
    {
        return $this->parseCsv($this->fetch($this->feedListUrl()));
    }

    /** Download a legacy feed and return the raw (decompressed) body. */
    public function downloadFeed(array $options = []): string
    {
        return $this->fetch($this->downloadUrl($options));
    }

    /**
     * GET a URL and return the body, transparently gunzipping a gzipped feed.
     *
     * Awin's /compression/gzip/ returns a gzip FILE body (magic \x1f\x8b), not
     * an HTTP Content-Encoding, so it is decompressed here explicitly rather
     * than relying on Guzzle's transparent handling.
     *
     * @throws \RuntimeException on transport failure, non-200, or bad gzip.
     */
    public function fetch(string $url): string
    {
        $client = $this->http ?? new Client(['timeout' => $this->timeout(), 'http_errors' => false]);

        try {
            $response = $client->get($url, [
                'headers' => ['Accept' => 'text/csv, application/octet-stream;q=0.9, */*;q=0.8'],
            ]);
        } catch (GuzzleException $e) {
            throw new \RuntimeException('Awin feed request failed: '.$e->getMessage(), 0, $e);
        }

        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new \RuntimeException(sprintf(
                'Awin feed request returned HTTP %d for %s',
                $status,
                $this->redact($url)
            ));
        }

        return $this->maybeGunzip((string) $response->getBody());
    }

    /** Decompress a gzip body if the magic bytes say so; otherwise pass through. */
    public function maybeGunzip(string $body): string
    {
        if (! str_starts_with($body, "\x1f\x8b")) {
            return $body;
        }

        $out = @gzdecode($body);
        if ($out === false) {
            throw new \RuntimeException('Awin feed body looked gzipped but could not be decompressed.');
        }

        return $out;
    }

    // ---------------------------------------------------------------------
    // Parsing (pure - no network, no DB)
    // ---------------------------------------------------------------------

    /**
     * Generic CSV -> list of assoc rows keyed by a normalised header.
     *
     * @return list<array<string, ?string>>
     */
    public function parseCsv(string $csv): array
    {
        $csv = $this->stripBom($csv);
        if (trim($csv) === '') {
            return [];
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);

        $rows = [];
        $header = null;

        while (($cols = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            // A completely blank line yields [null]; skip it wherever it appears.
            if ($cols === [null] || $cols === []) {
                continue;
            }

            if ($header === null) {
                $header = array_map(fn ($h) => $this->normaliseHeader((string) $h), $cols);
                continue;
            }

            $row = [];
            foreach ($header as $i => $name) {
                if ($name === '') {
                    continue;
                }
                $row[$name] = $cols[$i] ?? null;
            }

            // Skip a row that is empty in every mapped column.
            if (count(array_filter($row, fn ($v) => $v !== null && trim((string) $v) !== '')) === 0) {
                continue;
            }

            $rows[] = $row;
        }

        fclose($stream);

        return $rows;
    }

    /**
     * Parse an Awin product feed CSV into normalised rows.
     *
     * Fails LOUDLY when there is no product-name column, so a wrong file or a
     * format change is a visible error rather than a silent zero-match run
     * (Rule 5: the happy path must fail loudly).
     *
     * @return list<array{
     *   product_id: ?string, merchant_id: ?string, merchant_name: ?string,
     *   name: string, price: ?float, rrp: ?float, currency: ?string,
     *   in_stock: bool, in_stock_raw: ?string, stock_quantity: ?int,
     *   stock_status: ?string, gtin: ?string, ean: ?string, upc: ?string,
     *   mpn: ?string, brand: ?string, deep_link: ?string,
     *   merchant_deep_link: ?string, raw: array<string, ?string>
     * }>
     */
    public function parseFeed(string $csv): array
    {
        $raw = $this->parseCsv($csv);
        if ($raw === []) {
            return [];
        }

        $available = array_keys($raw[0]);
        $nameKey = $this->firstKey($available, ['product_name', 'name', 'title']);
        if ($nameKey === null) {
            throw new \RuntimeException(
                'Awin feed has no product name column (looked for product_name/name/title). '
                .'Columns seen: '.implode(', ', array_slice($available, 0, 30))
            );
        }

        $rows = [];
        foreach ($raw as $r) {
            $name = trim((string) ($r[$nameKey] ?? ''));
            if ($name === '') {
                continue;
            }

            $rows[] = [
                'product_id' => $this->firstValue($r, ['aw_product_id', 'product_id']),
                'merchant_id' => $this->firstValue($r, ['merchant_id']),
                'merchant_name' => $this->firstValue($r, ['merchant_name']),
                'name' => $name,
                'price' => $this->normalisePrice($this->firstValue($r, ['search_price', 'store_price', 'price'])),
                'rrp' => $this->normalisePrice($this->firstValue($r, ['rrp_price', 'rrp'])),
                'currency' => $this->firstValue($r, ['currency']),
                'in_stock' => $this->isInStock($this->firstValue($r, ['in_stock'])),
                'in_stock_raw' => $this->firstValue($r, ['in_stock']),
                'stock_quantity' => $this->intOrNull($this->firstValue($r, ['stock_quantity', 'number_available'])),
                'stock_status' => $this->firstValue($r, ['stock_status']),
                'gtin' => $this->firstValue($r, ['product_gtin', 'gtin', 'ean', 'upc', 'isbn']),
                'ean' => $this->firstValue($r, ['ean']),
                'upc' => $this->firstValue($r, ['upc']),
                'mpn' => $this->firstValue($r, ['mpn']),
                'brand' => $this->firstValue($r, ['brand_name', 'brand']),
                'deep_link' => $this->firstValue($r, ['aw_deep_link', 'deep_link', 'merchant_deep_link']),
                'merchant_deep_link' => $this->firstValue($r, ['merchant_deep_link', 'deep_link']),
                'raw' => $r,
            ];
        }

        return $rows;
    }

    /**
     * Awin's documented in_stock semantics (a rule, not a guess):
     *   '1'        -> in stock
     *   '0'        -> NOT in stock
     *   blank/''   -> NOT in stock (blank is assumed no stock)
     *   any other non-empty text -> treated as in stock
     *
     * Because "any other text = in stock" is surprising, callers that need a
     * stricter reading should also consult stock_status / stock_quantity.
     */
    public function isInStock(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        $v = strtolower(trim($value));
        if ($v === '' || $v === '0') {
            return false;
        }

        return true;
    }

    /**
     * Pull the first money amount out of a feed cell.
     *
     * Handles "110.00", "£1,234.56" and "GBP 2,107.43". Returns null when there
     * is no number at all, rather than a silent 0.0 that would read as free.
     */
    public function normalisePrice(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/(\d{1,3}(?:[,\s]\d{3})+(?:\.\d{1,2})?|\d+(?:\.\d{1,2})?)/', $value, $m) !== 1) {
            return null;
        }

        return (float) str_replace([',', ' '], '', $m[1]);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function feedBase(): string
    {
        $base = $this->clean(config('services.awin.feed_base')) ?? 'https://productdata.awin.com';

        return rtrim($base, '/');
    }

    private function apiBase(): string
    {
        $base = $this->clean(config('services.awin.api_base')) ?? 'https://api.awin.com';

        return rtrim($base, '/');
    }

    private function requireFeedKey(): string
    {
        $key = $this->feedApiKey();
        if ($key === null) {
            throw new \RuntimeException(
                'Awin feed api key is not configured (AWIN_FEED_API_KEY). '
                .'This is an owner-held credential - see config/services.php.'
            );
        }

        return $key;
    }

    /** Never let an api key reach a log or an error message. */
    private function redact(string $url): string
    {
        return (string) preg_replace('#/apikey/[^/]+#', '/apikey/***', $url);
    }

    private function stripBom(string $csv): string
    {
        return str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv;
    }

    private function normaliseHeader(string $header): string
    {
        $header = strtolower(trim($this->stripBom($header)));
        $header = (string) preg_replace('/[^a-z0-9]+/', '_', $header);

        return trim($header, '_');
    }

    private function clean(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  list<string>  $keys
     */
    private function firstKey(array $available, array $keys): ?string
    {
        $lookup = array_flip($available);
        foreach ($keys as $key) {
            if (isset($lookup[$key])) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param  array<string, ?string>  $row
     * @param  list<string>  $keys
     */
    private function firstValue(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
                return trim((string) $row[$key]);
            }
        }

        return null;
    }

    private function intOrNull(?string $value): ?int
    {
        if ($value === null || ! is_numeric(trim($value))) {
            return null;
        }

        return (int) trim($value);
    }
}
