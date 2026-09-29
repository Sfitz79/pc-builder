<?php

namespace App\Console\Commands;

use App\Models\Component;
use App\Services\PcppPriceService;
use App\Services\PriceIntegrityService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Refresh component prices and stock from live UK retailer listings.
 *
 * The catalogue was scraped once and has been drifting ever since: the publish
 * gate blocks every budget band because rows carry no price_checked_at and the
 * data is weeks old. This command re-derives price and stock from a live source
 * and stamps the row through PriceIntegrityService::markChecked(), which is the
 * only thing that makes a row publishable.
 *
 * Requires Byparr (see scripts/launch-byparr.ps1). The host must not be on a
 * VPN: PCPartPicker's Cloudflare challenge stalls on a VPN address and the pass
 * will time out on every item.
 */
class RefreshComponentPrices extends Command
{
    protected $signature = 'components:refresh-prices
        {--ids= : Comma-separated component ids to refresh}
        {--ids-file= : File containing ids or a JSON array of ids}
        {--category= : Only refresh one category slug}
        {--limit=0 : Maximum rows to process this run (0 = no limit)}
        {--force : Re-check rows already checked recently}
        {--dry-run : Report what would change without writing}
        {--tolerance= : Ignore live differences below this many pounds}
        {--skip-merchant-check : Take the cheapest PCPP offer without confirming stock at the retailer (NOT for production writes)}
        {--byparr= : Byparr base URL, overriding BYPARR_URL}';

    protected $description = 'Refresh component prices and stock from live UK retailer listings via PCPartPicker + Byparr';

    public function handle(PcppPriceService $pcpp, PriceIntegrityService $integrity): int
    {
        if ($this->option('byparr') !== null) {
            config()->set('services.byparr.url', $this->option('byparr'));
            $pcpp = app()->makeWith(PcppPriceService::class, ['byparrUrl' => $this->option('byparr')]);
        }

        if (! $pcpp->configured()) {
            $this->error('No price lane configured. Set SCRAPER_API_KEY or BYPARR_URL (or pass --byparr=http://localhost:8191/v1)');
            $this->line('  Byparr:        powershell -ExecutionPolicy Bypass -File scripts\launch-byparr.ps1');
            $this->line('  ScraperAPI:    add SCRAPER_API_KEY to .env (cloud browser, no local process)');

            return self::FAILURE;
        }

        $query = Component::query()->where('active', true);

        $ids = $this->resolveIds();
        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        if (($slug = $this->option('category')) !== null) {
            $category = \App\Models\Category::query()->where('slug', $slug)->first();
            if ($category === null) {
                $this->error("No category with slug '{$slug}'.");

                return self::FAILURE;
            }
            $query->where('category_id', $category->id);
        }

        if (! $this->option('force')) {
            $query->where(function ($q) {
                $q->whereNull('price_checked_at')
                    ->orWhere('price_checked_at', '<', now()->subDays(PriceIntegrityService::FRESH_DAYS)->toDateTimeString());
            });
        }

        $total = (clone $query)->count();
        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $rows = $query->get();
        $tolerance = $this->option('tolerance') !== null
            ? (float) $this->option('tolerance')
            : PcppPriceService::SIGNIFICANT_DELTA;
        $dryRun = (bool) $this->option('dry-run');
        $verifyMerchant = ! $this->option('skip-merchant-check');

        $this->line("Lane        : ".$pcpp->laneDescription());
        $this->line("Candidates  : ".$total);
        $this->line('Processing  : '.$rows->count().($limit > 0 ? " (capped at --limit={$limit})" : ''));
        $this->line('Tolerance   : GBP '.$tolerance.'   Mode: '.($dryRun ? 'DRY RUN' : 'write'));
        $this->line('Merchant    : '.($verifyMerchant
            ? 'confirming stock at the retailer before every write'
            : 'PCPP availability only - NOT SAFE FOR PRODUCTION'));
        $this->newLine();

        $stats = [
            'refreshed' => 0, 'unchanged' => 0, 'raised' => 0, 'lowered' => 0,
            'out_of_stock' => 0, 'no_url' => 0, 'failed' => 0,
        ];
        $corrections = new Collection();
        $failures = new Collection();

        $bar = $this->output->createProgressBar($rows->count());
        $bar->start();

        foreach ($rows as $component) {
            $sourceUrl = (string) ($component->source_url ?? '');

            if ($sourceUrl === '' || ! $pcpp->supports($sourceUrl)) {
                $stats['no_url']++;
                $bar->advance();

                continue;
            }

            try {
                // Production writes must be confirmed at the retailer: PCPP's own
                // availability cell is sometimes simply empty, so "PCPP says in
                // stock" is not proof we can buy the part. --skip-merchant-check
                // exists for measuring bands, never for a write run.
                $offer = $verifyMerchant
                    ? $pcpp->cheapestConfirmedOfferFor($sourceUrl)
                    : $pcpp->cheapestOfferFor($sourceUrl);
            } catch (\Throwable $e) {
                $stats['failed']++;
                $failures->push([
                    'id' => $component->id,
                    'name' => (string) $component->name,
                    'error' => $e->getMessage(),
                ]);
                $bar->advance();

                continue;
            }

            $oldPrice = (float) $component->price;
            $delta = $offer['price'] - $oldPrice;

            if (abs($delta) <= $tolerance) {
                $stats['unchanged']++;
                if (! $dryRun) {
                    // Same price, but the row is still stamped, which is what
                    // makes it publishable.
                    $integrity->markChecked(
                        $component,
                        $oldPrice,
                        $offer['in_stock'] ? 1 : 0,
                        $offer['merchant_url'] ?? null,
                    );
                }
            } else {
                $stats['refreshed']++;
                $delta > 0 ? $stats['raised']++ : $stats['lowered']++;

                $corrections->push([
                    'id' => $component->id,
                    'name' => (string) $component->name,
                    'was' => $oldPrice,
                    'now' => $offer['price'],
                    'delta' => $delta,
                    'merchant' => $offer['merchant'],
                    'in_stock' => $offer['in_stock'],
                    'source_url' => $offer['merchant_url'] ?? $sourceUrl,
                ]);

                if (! $dryRun) {
                    $integrity->markChecked(
                        $component,
                        $offer['price'],
                        $offer['in_stock'] ? 1 : 0,
                        $offer['merchant_url'] ?? null,
                    );
                }
            }

            if (! $offer['in_stock']) {
                $stats['out_of_stock']++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->line('=== summary ===');
        $this->line('  price corrected : '.$stats['refreshed'].'  (raised '.$stats['raised'].', lowered '.$stats['lowered'].')');
        $this->line('  within tolerance: '.$stats['unchanged']);
        $this->line('  out of stock    : '.$stats['out_of_stock']);
        $this->line('  no source url   : '.$stats['no_url']);
        $this->line('  failed          : '.$stats['failed']);
        $this->line('  '.($dryRun ? 'DRY RUN - nothing was written' : 'written through PriceIntegrityService::markChecked()'));

        if ($corrections->isNotEmpty()) {
            $this->newLine();
            $this->line('=== largest corrections ===');
            $corrections->sortByDesc(fn ($c) => abs($c['delta']))->take(15)->each(function ($c) {
                $this->line(sprintf(
                    '  %s  %-42s GBP %8.2f -> %8.2f  (%+.2f)  %s%s',
                    str_pad((string) $c['id'], 5),
                    mb_substr($c['name'], 0, 42),
                    $c['was'],
                    $c['now'],
                    $c['delta'],
                    (string) $c['merchant'],
                    $c['in_stock'] ? '' : '  [OUT OF STOCK]'
                ));
            });
            $more = $corrections->count() - 15;
            if ($more > 0) {
                $this->line("  ... and {$more} more");
            }
        }

        if ($failures->isNotEmpty()) {
            $this->newLine();
            $this->line('=== failures ===');
            $failures->take(10)->each(function ($f) {
                $this->line('  '.$f['id'].'  '.mb_substr($f['name'], 0, 40).'  '.$f['error']);
            });
            $more = $failures->count() - 10;
            if ($more > 0) {
                $this->line("  ... and {$more} more");
            }

            // A run where nothing succeeded is a broken proxy, not bad luck.
            if ($stats['refreshed'] === 0 && $stats['unchanged'] === 0) {
                $this->error('Every fetch failed - check Byparr is running and no VPN is active.');

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return list<int>|null
     */
    private function resolveIds(): ?array
    {
        $ids = $this->option('ids');
        $file = $this->option('ids-file');

        if ($ids === null && $file === null) {
            return null;
        }

        $raw = (string) ($ids ?? '');

        if ($file !== null) {
            $contents = @file_get_contents((string) $file);
            if ($contents === false) {
                $this->error("Could not read ids file: {$file}");

                return null;
            }

            $decoded = json_decode($contents, true);
            // Accept both a bare JSON array and {"ids": [...]} so the tier
            // export and a hand-written list both work.
            $raw = is_array($decoded)
                ? implode(',', $decoded['ids'] ?? $decoded)
                : $contents;
        }

        $ids = array_values(array_filter(array_map(
            fn ($v) => (int) trim((string) $v),
            preg_split('/[\s,]+/', $raw) ?: []
        )));

        return $ids === [] ? null : $ids;
    }
}
