<?php
// Genie 2026-09-29: the Awin feed client must match Awin's DOCUMENTED endpoints
// and column semantics. These tests exist because a client written from memory
// is exactly how the Byparr call went wrong before (Rule 4: verify the shape
// from the source, not from recollection).
//
// Endpoint shapes pinned here (Awin developer docs, checked 2026-09-29):
//   list     https://productdata.awin.com/datafeed/list/apikey/{key}
//   download https://productdata.awin.com/datafeed/download/apikey/{key}/columns/{c}/format/csv/delimiter/%2C/compression/gzip/
//   enhanced https://api.awin.com/publishers/{pid}/awinfeeds/download/{adv}-retail-en_GB.jsonl
namespace Tests\Unit;

use App\Services\AwinFeedService;
use Tests\TestCase;

class AwinFeedServiceTest extends TestCase
{
    private function svc(?string $key = 'TESTKEY'): AwinFeedService
    {
        return new AwinFeedService(feedApiKey: $key);
    }

    private function fixtureCsv(): string
    {
        return <<<'CSV'
product_name,search_price,store_price,in_stock,stock_quantity,ean,mpn,brand_name,aw_deep_link,merchant_deep_link,merchant_name,aw_product_id,stock_status,currency
"Corsair Vengeance RGB 32GB Kit, Black",399.99,379.99,1,42,0794025011271,CMW32GX2M2C3000K,Corsair,https://awin.1trn.net/x?p=1,https://www.scan.co.uk/products/sku,Scan,12345,available,GBP
AMD Ryzen 7 5800X,£319.99,,0,,,2000913RKHXBQ,AMD,https://awin.1trn.net/x?p=2,https://www.overclockers.co.uk/p/y,Overclockers UK,12346,not available,GBP
"Fractal Define R5, Black",199.00,199.00,,7,,FD-C-R5,FRACTAL DESIGN,https://awin.1trn.net/x?p=3,https://www.corsair.com/p/z,Corsair,12347,available,GBP

CSV;
    }

    // -- URLs ------------------------------------------------------------

    public function test_feed_list_url_matches_documented_shape(): void
    {
        $this->assertSame(
            'https://productdata.awin.com/datafeed/list/apikey/TESTKEY',
            $this->svc()->feedListUrl()
        );
    }

    public function test_download_url_matches_documented_shape(): void
    {
        $url = $this->svc()->downloadUrl([
            'columns' => ['aw_product_id', 'product_name', 'search_price', 'in_stock'],
        ]);

        $this->assertSame(
            'https://productdata.awin.com/datafeed/download/apikey/TESTKEY'
            .'/columns/aw_product_id,product_name,search_price,in_stock'
            .'/format/csv/delimiter/%2C/compression/gzip',
            $url
        );
    }

    public function test_download_url_accepts_merchant_category_and_brand_filters(): void
    {
        $url = $this->svc()->downloadUrl([
            'columns' => ['product_name'],
            'mid' => [1234, 5678],
            'cid' => '10,11',
        ]);

        $this->assertStringEndsWith('/mid/1234,5678/cid/10,11', $url);
        $this->assertStringContainsString('/format/csv', $url);
    }

    public function test_enhanced_feed_url_is_a_jsonl_publisher_path(): void
    {
        $svc = new AwinFeedService(feedApiKey: 'K', publisherId: '99999');
        $this->assertSame(
            'https://api.awin.com/publishers/99999/awinfeeds/download/12345-retail-en_GB.jsonl',
            $svc->enhancedDownloadUrl('12345')
        );
    }

    public function test_missing_credentials_fail_loudly(): void
    {
        config(['services.awin.feed_api_key' => null, 'services.awin.publisher_id' => null]);

        $svc = new AwinFeedService();
        $this->assertFalse($svc->configured());
        $this->assertFalse($svc->apiConfigured());

        $this->expectException(\RuntimeException::class);
        $svc->feedListUrl();
    }

    public function test_enhanced_url_needs_publisher_id(): void
    {
        // The service falls back to config, which carries the real publisher ID
        // in dev; clear it so this test asserts the constructor param, not the
        // environment.
        config(['services.awin.publisher_id' => null]);

        $svc = new AwinFeedService(feedApiKey: 'K', publisherId: null, apiToken: 'T');
        $this->expectException(\RuntimeException::class);
        $svc->enhancedDownloadUrl('12345');
    }

    /**
     * The owner supplied the Awin publisher/user ID (2850823) on 2026-09-29
     * believing it might unlock the feed. It does not, and this pins why.
     *
     * Probed live against productdata.awin.com:
     *   - no key                  -> HTTP 403 "No API key supplied"
     *   - the publisher ID as key -> HTTP 403 "Your access is disabled"
     *
     * So the ID identifies the account and is required for the enhanced JSONL
     * path, but it is neither sufficient nor usable as a credential. Both
     * lanes still need an owner-held secret.
     */
    public function test_a_publisher_id_alone_cannot_authenticate_a_feed(): void
    {
        // No feed key: the CSV lane is unavailable regardless of the ID.
        config(['services.awin.feed_api_key' => null]);
        $noKey = new AwinFeedService(feedApiKey: null, publisherId: '2850823');
        $this->expectException(\RuntimeException::class);
        $noKey->feedListUrl();
    }

    /**
     * ...but the ID IS required for the enhanced lane, and the URL is well
     * formed with it. Recorded here so the day the token arrives, the shape is
     * already proven against the real account id.
     */
    public function test_the_real_publisher_id_builds_a_valid_enhanced_url(): void
    {
        $svc = new AwinFeedService(feedApiKey: null, publisherId: '2850823');

        $url = $svc->enhancedDownloadUrl('12345');

        $this->assertStringContainsString('/publishers/2850823/awinfeeds/download/', $url);
        $this->assertStringContainsString('12345-retail-en_GB.jsonl', $url);
    }

    // -- Prices ----------------------------------------------------------

    public function test_price_normalisation_handles_currency_and_separators(): void
    {
        $svc = $this->svc();

        $this->assertSame(110.0, $svc->normalisePrice('110.00'));
        $this->assertSame(1234.56, $svc->normalisePrice('£1,234.56'));
        $this->assertSame(2107.43, $svc->normalisePrice('GBP 2,107.43'));
        $this->assertSame(319.99, $svc->normalisePrice('  £319.99  '));
    }

    public function test_a_price_with_no_number_is_null_not_zero(): void
    {
        $svc = $this->svc();

        // null, never 0.0 - a 0.0 would read as a free part.
        $this->assertNull($svc->normalisePrice('See website'));
        $this->assertNull($svc->normalisePrice(''));
        $this->assertNull($svc->normalisePrice(null));
    }

    // -- in_stock semantics (documented, and surprising) -----------------

    public function test_in_stock_follows_awin_documented_semantics(): void
    {
        $svc = $this->svc();

        $this->assertTrue($svc->isInStock('1'));
        $this->assertFalse($svc->isInStock('0'));
        // Blank means NO stock, not unknown-but-true.
        $this->assertFalse($svc->isInStock(''));
        $this->assertFalse($svc->isInStock(null));
        // Any other non-empty text is treated as in stock by Awin's rule.
        $this->assertTrue($svc->isInStock('yes'));
    }

    // -- Parsing ---------------------------------------------------------

    public function test_parse_feed_maps_columns_and_keeps_quoted_commas(): void
    {
        $rows = $this->svc()->parseFeed($this->fixtureCsv());

        $this->assertCount(3, $rows, 'the blank line must not become a row');

        $ram = $rows[0];
        // The name contains a comma and must survive intact.
        $this->assertSame('Corsair Vengeance RGB 32GB Kit, Black', $ram['name']);
        $this->assertSame(399.99, $ram['price']);
        $this->assertTrue($ram['in_stock']);
        $this->assertSame(42, $ram['stock_quantity']);
        $this->assertSame('0794025011271', $ram['gtin']);
        $this->assertSame('CMW32GX2M2C3000K', $ram['mpn']);
        $this->assertSame('Corsair', $ram['brand']);
        $this->assertSame('https://www.scan.co.uk/products/sku', $ram['merchant_deep_link']);

        // £ in the cell and a blank in_stock on the case row.
        $this->assertSame(319.99, $rows[1]['price']);
        $this->assertFalse($rows[1]['in_stock']);
        $this->assertNull($rows[1]['gtin']);

        $this->assertSame(199.0, $rows[2]['price']);
        $this->assertFalse($rows[2]['in_stock'], 'blank in_stock means NOT in stock');
    }

    public function test_parse_feed_normalises_headers_and_ignores_case(): void
    {
        $csv = "Product_Name,GTIN,PRICE,IN_STOCK\nAsus TUF,1901980648299,120.00,1\n";
        $rows = $this->svc()->parseFeed($csv);

        $this->assertCount(1, $rows);
        $this->assertSame('Asus TUF', $rows[0]['name']);
        $this->assertSame('1901980648299', $rows[0]['gtin']);
        $this->assertSame(120.0, $rows[0]['price']);
        $this->assertTrue($rows[0]['in_stock']);
    }

    public function test_a_feed_without_a_name_column_fails_loudly(): void
    {
        // A wrong file must be a visible error, not a silent zero-match run.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no product name column/');

        $this->svc()->parseFeed("id,price\n1,10.00\n");
    }

    public function test_empty_feed_is_an_empty_list(): void
    {
        $this->assertSame([], $this->svc()->parseFeed(''));
        $this->assertSame([], $this->svc()->parseFeed("\n\n"));
    }

    public function test_gzip_bodies_are_decompressed_and_plain_bodies_pass_through(): void
    {
        $svc = $this->svc();
        $csv = $this->fixtureCsv();

        $gz = gzencode($csv);
        $this->assertStringStartsWith("\x1f\x8b", $gz, 'fixture must really be gzip');
        $this->assertSame($csv, $svc->maybeGunzip($gz));
        $this->assertSame($csv, $svc->maybeGunzip($csv));
    }

    public function test_undecompressable_gzip_is_an_error_not_a_silent_pass(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->svc()->maybeGunzip("\x1f\x8b" . 'not really gzip');
    }
}
