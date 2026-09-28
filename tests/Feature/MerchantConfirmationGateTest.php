<?php
// Genie 2026-09-28: 27 assertions covering the merchant-confirmation gate,
// including the fail-safe direction (unreadable page => not confirmed) and the
// word-boundary bug where strip_tags() welded "basket"+"In" together.
namespace Tests\Feature;

use App\Services\PcppPriceService;
use Tests\TestCase;

class MerchantConfirmationGateTest extends TestCase
{
    private int $pass = 0;

    private int $fail = 0;

    /** @var list<string> */
    private array $log = [];

    private function check(string $name, bool $ok, string $detail = ''): void
    {
        if ($ok) {
            $this->pass++;
            $this->log[] = "  PASS  {$name}";
        } else {
            $this->fail++;
            $this->log[] = "  FAIL  {$name}".($detail !== '' ? "  ({$detail})" : '');
        }
    }

    private function heading(string $text): void
    {
        $this->log[] = '';
        $this->log[] = $text;
    }

    public function test_gate_is_fail_safe_and_walks_to_a_confirmable_offer(): void
    {
        $svc = new FakePcpp();

        $this->heading('=== fail-safe behaviour ===');

        $r = $svc->confirmAtMerchant(null);
        $this->check('null destination is not confirmed', $r['confirmed'] === false);
        $this->check('null destination explains itself', str_contains($r['reason'], 'no usable merchant destination'));

        $svc->pages['https://x.test/cap'] = '<html>Just a moment...</html>';
        $this->check('challenge page is not confirmed', $svc->confirmAtMerchant('https://x.test/cap')['confirmed'] === false);

        $svc->pages['https://x.test/404'] = '<html><body>Page Not Found</body></html>';
        $this->check('404 page is not confirmed', $svc->confirmAtMerchant('https://x.test/404')['confirmed'] === false);

        // Amazon's interstitial is HTTP 200 with no stock language at all, so it
        // is only catchable by content. Without this the gate could pass a page
        // that says nothing about availability.
        $svc->pages['https://x.test/amazon'] = '<!DOCTYPE html><html><head><title>Amazon.co.uk</title></head><body>'
            .'<h4>Click the button below to continue shopping</h4>'
            .'<!-- To discuss automated access to Amazon data please contact api-services-support@amazon.com. -->'
            .'</body></html>';
        $r = $svc->confirmAtMerchant('https://x.test/amazon', 'amazonuk');
        $this->check('anti-bot interstitial is not confirmed', $r['confirmed'] === false, json_encode($r));
        $this->check('interstitial is named as the cause', str_contains($r['reason'], 'interstitial'));

        $svc->pages['https://x.test/oos'] = '<html><body><button>Add to basket</button> Currently unavailable</body></html>';
        $r = $svc->confirmAtMerchant('https://x.test/oos');
        $this->check('out of stock beats add-to-basket', $r['confirmed'] === false, json_encode($r));
        $this->check('out of stock names the reason', str_contains($r['reason'], 'out of stock'));

        $svc->pages['https://x.test/soldout'] = '<html>Add to cart - Sold Out</html>';
        $this->check('sold out is not confirmed', $svc->confirmAtMerchant('https://x.test/soldout')['confirmed'] === false);

        $svc->pages['https://x.test/notify'] = '<html>Add to cart <a>Notify me when available</a></html>';
        $this->check('notify-me template is not confirmed', $svc->confirmAtMerchant('https://x.test/notify')['confirmed'] === false);

        $svc->pages['https://x.test/quiet'] = '<html><body>Product details</body></html>';
        $this->check('ambiguous page is not confirmed', $svc->confirmAtMerchant('https://x.test/quiet')['confirmed'] === false);

        // The regression that produced a false negative: strip_tags() glued the
        // two text nodes, so "Add to basket" lost its trailing word boundary.
        $svc->pages['https://x.test/ok'] = '<html><body><button>Add to basket</button><span>In stock</span></body></html>';
        $r = $svc->confirmAtMerchant('https://x.test/ok', 'Scan');
        $this->check('adjacent tags do not break the buy signal', $r['confirmed'] === true, json_encode($r));
        $this->check('confirmation names the merchant', str_contains($r['reason'], 'Scan'));

        $svc->pages['https://x.test/scripted'] = '<html><script>var s="in stock";</script><body>Sold Out</body></html>';
        $this->check('script contents cannot fake a buy signal', $svc->confirmAtMerchant('https://x.test/scripted')['confirmed'] === false);

        $broken = new FakePcpp(throw: true);
        $r = $broken->confirmAtMerchant('https://x.test/anything');
        $this->check('transport failure is not confirmed', $r['confirmed'] === false);
        $this->check(
            'transport failure exposes the real cause',
            str_contains($r['reason'], 'Timeout 15000ms'),
            $r['reason']
        );

        $this->heading('=== walking to a confirmable offer ===');

        $svc2 = new FakePcpp();
        $svc2->pages['https://cheap.test/p'] = '<html>Currently unavailable</html>';
        $svc2->pages['https://mid.test/p'] = '<html><button>Add to basket</button></html>';

        $offer = $svc2->cheapestConfirmedOffer([
            ['price' => 100.0, 'in_stock' => true, 'merchant' => 'amazonuk', 'merchant_url' => 'https://cheap.test/p', 'base' => null, 'shipping' => null],
            ['price' => 120.0, 'in_stock' => true, 'merchant' => 'scancouk', 'merchant_url' => 'https://mid.test/p', 'base' => null, 'shipping' => null],
            ['price' => 140.0, 'in_stock' => true, 'merchant' => 'ccl', 'merchant_url' => 'https://third.test/p', 'base' => null, 'shipping' => null],
        ]);

        $this->check('falls through to the next confirmable offer', $offer['merchant'] === 'scancouk', (string) $offer['merchant']);
        $this->check('reports the confirmed price', abs($offer['price'] - 120.0) < 0.001);
        $this->check('records the unconfirmable skip', count($offer['skipped']) === 1, json_encode($offer['skipped']));
        $this->check('skipped entry keeps its reason', str_contains($offer['skipped'][0]['reason'], 'out of stock'));
        $this->check('merchant_url is carried through', $offer['merchant_url'] === 'https://mid.test/p');

        $svc3 = new FakePcpp();
        $svc3->pages['https://a.test/p'] = '<html>Currently unavailable</html>';
        $svc3->pages['https://b.test/p'] = '<html>Product details</html>';

        $threw = false;
        $msg = '';
        try {
            $svc3->cheapestConfirmedOffer([
                ['price' => 10.0, 'in_stock' => true, 'merchant' => 'a', 'merchant_url' => 'https://a.test/p', 'base' => null, 'shipping' => null],
                ['price' => 20.0, 'in_stock' => true, 'merchant' => 'b', 'merchant_url' => 'https://b.test/p', 'base' => null, 'shipping' => null],
            ]);
        } catch (\RuntimeException $e) {
            $threw = true;
            $msg = $e->getMessage();
        }
        $this->check('no confirmable offer throws rather than guessing', $threw);
        $this->check('the throw names every attempt', str_contains($msg, 'a:') && str_contains($msg, 'b:'), $msg);

        $this->heading('=== affiliate unwrapping ===');

        $tracked = '<table><tr>'
            .'<td class="td__logo"><a href="https://www.awin1.com/cread.php?awinmid=15473&amp;ued=https%3A%2F%2Fwww.scan.co.uk%2Fproducts%2Fcpu" data-merchant-tag="scancouk">Scan</a></td>'
            .'<td class="td__base">£369.98</td>'
            .'<td class="td__availability td__availability--inStock">In stock</td>'
            .'<td class="td__finalPrice"><a href="https://www.awin1.com/cread.php?awinmid=15473&amp;ued=https%3A%2F%2Fwww.scan.co.uk%2Fproducts%2Fcpu">£369.98</a></td>'
            .'</tr></table>';

        $parsed = $svc4 = (new FakePcpp())->extractRows($tracked);
        $this->check('one offer row parsed', count($parsed) === 1);
        $this->check('price parsed', abs($parsed[0]['price'] - 369.98) < 0.001, json_encode($parsed[0]));
        $this->check('in_stock parsed', $parsed[0]['in_stock'] === true);
        $this->check('merchant tag parsed', $parsed[0]['merchant'] === 'scancouk');
        $this->check(
            'awin tracker is unwrapped to the retailer URL',
            $parsed[0]['merchant_url'] === 'https://www.scan.co.uk/products/cpu',
            (string) $parsed[0]['merchant_url']
        );

        $direct = '<table><tr>'
            .'<td class="td__logo"><a href="https://www.amazon.co.uk/dp/B0DKFMSMYK?tag=AssocID" data-merchant-tag="amazonuk">Amazon</a></td>'
            .'<td class="td__base">£355.89</td>'
            .'<td class="td__availability "></td>'
            .'<td class="td__finalPrice"><a href="https://www.amazon.co.uk/dp/B0DKFMSMYK?tag=AssocID">£355.89</a></td>'
            .'</tr></table>';
        $parsed2 = (new FakePcpp())->extractRows($direct);
        $this->check('direct merchant link is kept as-is', $parsed2[0]['merchant_url'] === 'https://www.amazon.co.uk/dp/B0DKFMSMYK?tag=AssocID');
        $this->check('empty availability cell is NOT in stock', $parsed2[0]['in_stock'] === false);

        $this->log[] = '';
        $this->log[] = $this->fail === 0
            ? "{$this->pass} passed, 0 failed"
            : "{$this->pass} passed, {$this->fail} FAILED";

        fwrite(STDERR, PHP_EOL.implode(PHP_EOL, $this->log).PHP_EOL);

        $this->assertSame(0, $this->fail, 'merchant confirmation gate must never fail open');
    }
}

/**
 * Stubs the Byparr transport so the gate's logic is testable without a browser.
 */
final class FakePcpp extends PcppPriceService
{
    /** @var array<string, string> */
    public array $pages = [];

    public function __construct(private readonly bool $throw = false)
    {
        parent::__construct('http://127.0.0.1:8191/v1');
    }

    public function fetchHtml(string $url): string
    {
        if ($this->throw) {
            throw new \RuntimeException('Byparr transport error: detail: browser error: Timeout 15000ms exceeded.');
        }

        return $this->pages[$url] ?? '<html><body>nothing here</body></html>';
    }
}
