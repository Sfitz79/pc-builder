<?php

namespace App\Console\Commands;

use App\Models\Component;
use App\Services\ComponentImageService;
use App\Services\PriceIntegrityService;
use App\Services\PcppPriceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Full-catalogue refresh: price + stock + availability + image, one fetch each.
 *
 * Why this exists: PCPartPicker is behind Cloudflare, so a plain HTTP client
 * cannot read it, and 2,715 of 2,729 catalogue rows had never been price
 * checked. The market moved hard in 2026 (TrendForce: DRAM +13-18% and NAND
 * +10-15% QoQ through Q3, Gartner +130% combined DRAM/SSD year on year), so a
 * catalogue captured in early September was under-pricing memory, storage and
 * graphics - i.e. we were losing margin on every build we sold.
 *
 * The point of doing price and image in one command is that a single Byparr
 * fetch of the product page contains BOTH the merchant price table and the
 * og:image tag. Doing them separately doubles the page fetches, and on 2,708
 * components that is hours of avoidable Cloudflare traffic.
 *
 * Resumable by design: rows already checked inside FRESH_DAYS are skipped unless
 * --force is passed, so an interrupted run can simply be re-launched. That
 * matters because a full pass takes hours.
 */
class RefreshCatalogue extends Command
{
    protected $signature = 'components:refresh-catalogue
        {--ids-file= : restrict to a newline/comma separated id list}
        {--category= : restrict to one category slug (gpu, ram, storage, cpu, motherboard, psu, case, cooler)}
        {--force : re-check rows even if their price is still fresh}
        {--limit=0 : stop after this many components (0 = all)}
        {--offset=0 : skip this many components, for splitting a pass across runs}
        {--delay=1.2 : seconds between components, to stay polite to PCPP}
        {--attempts=3 : tries per component before moving on}
        {--no-image : skip image resolution, price and stock only}
        {--no-price : skip price writes, images only}
        {--dry-run : report everything, write nothing}';

    protected $description = 'Refresh the whole catalogue: live UK price, stock and product image from PCPartPicker via Byparr';

    public function handle(
        PcppPriceService $pcpp,
        ComponentImageService $images,
        PriceIntegrityService $integrity,
    ): int {
        if (! $pcpp->configured()) {
            $this->error('Byparr is not configured. Set BYPARR_URL or start pc-builder\\scripts\\launch-byparr.ps1');

            return self::FAILURE;
        }

        $query = Component::query()
            ->whereNotNull('source_url')
            ->where('source_url', '!=', '')
            ->where('active', true);

        if ($category = $this->option('category')) {
            $query->whereHas('category', fn ($q) => $q->where('slug', $category));
        }

        $ids = $this->idsFromFile();

        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        if (! $this->option('force')) {
            // Skip anything already verified recently. This is what makes the
            // pass resumable instead of a 2,700-row treadmill.
            $query->where(function ($q) {
                $q->whereNull('price_checked_at')
                    ->orWhere('price_checked_at', '')
                    ->orWhere('price_checked_at', '<', now()->subDays(PriceIntegrityService::FRESH_DAYS)->toDateTimeString());
            });
        }

        // Highest value first: those are the rows where a wrong price costs the
        // most money, so a partially completed pass is still a useful pass.
        $total = (clone $query)->count();

        $components = $query->orderByDesc('price')
            ->offset((int) $this->option('offset'))
            ->limit((int) $this->option('limit') ?: 1000000)
            ->get();

        $this->info(sprintf(
            'Refreshing %d of %d eligible components (ordered by price, highest first).%s',
            $components->count(),
            $total,
            $this->option('dry-run') ? '  DRY RUN - nothing will be written' : ''
        ));

        $dryRun = (bool) $this->option('dry-run');
        $doImage = ! $this->option('no-image');
        $doPrice = ! $this->option('no-price');
        $delay = (float) $this->option('delay');
        $attempts = max(1, (int) $this->option('attempts'));

        $tally = [
            'price_changed' => 0, 'price_same' => 0, 'out_of_stock' => 0,
            'image_new' => 0, 'image_same' => 0, 'no_offers' => 0, 'failed' => 0,
        ];
        $raised = 0;
        $lowered = 0;
        $touched = 0;

        foreach ($components as $i => $component) {
            $result = null;
            $error = null;

            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                try {
                    // ONE fetch, used for both price and image.
                    $html = $pcpp->fetchHtml((string) $component->source_url);

                    $offer = $pcpp->cheapestOffer($pcpp->extractRows($html));
                    $og = $doImage ? $images->extractOgImage($html) : null;

                    $imgMeta = null;
                    if ($og !== null) {
                        foreach ($images->candidatesFor($og) as $candidate) {
                            $imgMeta = $images->download($candidate);
                            if ($imgMeta !== null) {
                                break;
                            }
                        }
                    }

                    $result = compact('offer', 'og', 'imgMeta');
                    $error = null;
                    break;
                } catch (\Throwable $e) {
                    $error = $e->getMessage();

                    if ($attempt < $attempts) {
                        sleep(min(10, $attempt * 3));
                    }
                }
            }

            $touched++;

            if ($result === null) {
                $tally['failed']++;
                $this->line(sprintf('  [%d/%d] x %-42s %s', $i + 1, $components->count(), $this->label($component), $error));

                if ($delay > 0) {
                    usleep((int) round($delay * 1000000));
                }

                continue;
            }

            $changes = [];

            // ---- price + stock -------------------------------------------------
            if ($doPrice) {
                if ($result['offer'] === null) {
                    $tally['no_offers']++;
                    $changes[] = 'no-offers';
                } else {
                    $newPrice = (float) $result['offer']['price'];
                    $inStock = (bool) $result['offer']['in_stock'];
                    $delta = $newPrice - (float) $component->price;

                    if ($inStock) {
                        if (abs($delta) > PcppPriceService::SIGNIFICANT_DELTA) {
                            if ($delta > 0) {
                                $raised++;
                            } else {
                                $lowered++;
                            }
                            $tally['price_changed']++;
                            $changes[] = sprintf('price %s -> %s (%+.2f)', number_format((float) $component->price, 2), number_format($newPrice, 2), $delta);
                        } else {
                            $tally['price_same']++;
                        }

                        if (! $dryRun) {
                            $integrity->markChecked($component, $newPrice, (int) $component->stock ?: 1);
                        }
                    } else {
                        // Nothing in stock anywhere. That is a live answer, and
                        // the honest one is to mark it out of stock rather than
                        // keep advertising a part nobody can buy.
                        $tally['out_of_stock']++;
                        $changes[] = 'OUT OF STOCK';

                        if (! $dryRun) {
                            $component->update(['stock' => 0, 'price_checked_at' => now()->toDateTimeString()]);
                        }
                    }
                }
            }

            // ---- image ---------------------------------------------------------
            if ($doImage && $result['imgMeta'] !== null) {
                $cached = $dryRun ? null : $images->writeCache($component, $result['imgMeta']);

                if (str_contains((string) $component->image_url, '.256p.')
                    || (string) $component->image_url === ''
                    || str_contains((string) $component->image_url, 'm.media-amazon.com')) {
                    $tally['image_new']++;
                    $changes[] = sprintf('image %s %dx%d', $cached !== null ? '(cached)' : '(url)', $result['imgMeta']['width'], $result['imgMeta']['height']);
                } else {
                    $tally['image_same']++;
                }

                if (! $dryRun) {
                    $component->update(['image_url' => $result['imgMeta']['url']]);
                }
            }

            if ($changes !== []) {
                $this->line(sprintf('  [%d/%d] %-42s %s', $i + 1, $components->count(), $this->label($component), implode('; ', $changes)));
            }

            if ($delay > 0) {
                usleep((int) round($delay * 1000000));
            }

            if ($touched % 25 === 0) {
                $this->info(sprintf(
                    '  ... %d/%d done | raised %d lowered %d oos %d no-offers %d failed %d',
                    $touched, $components->count(), $raised, $lowered,
                    $tally['out_of_stock'], $tally['no_offers'], $tally['failed']
                ));
                Log::info('components:refresh-catalogue progress', $tally + ['touched' => $touched, 'raised' => $raised, 'lowered' => $lowered]);
            }
        }

        $this->info('');
        $this->info('=== refresh summary ===');
        $this->info(sprintf('  processed      : %d', $touched));
        $this->info(sprintf('  price changed  : %d  (raised %d, lowered %d)', $tally['price_changed'], $raised, $lowered));
        $this->info(sprintf('  price within +-1: %d', $tally['price_same']));
        $this->info(sprintf('  out of stock   : %d', $tally['out_of_stock']));
        $this->info(sprintf('  no offers      : %d', $tally['no_offers']));
        $this->info(sprintf('  images set     : %d  (already had: %d)', $tally['image_new'], $tally['image_same']));
        $this->info(sprintf('  failed         : %d', $tally['failed']));

        if ($dryRun) {
            $this->info('  DRY RUN - nothing was written');
        }

        return self::SUCCESS;
    }

    /**
     * @return list<int>|null
     */
    protected function idsFromFile(): ?array
    {
        $path = $this->option('ids-file');

        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        if (! is_file($path)) {
            $this->error("ids file not found: {$path}");

            return null;
        }

        $ids = array_values(array_unique(array_filter(array_map(
            'intval',
            preg_split('/[\s,]+/', (string) file_get_contents($path)) ?: []
        ))));

        if ($ids === []) {
            $this->error("ids file had no usable ids: {$path}");

            return null;
        }

        $this->info('Restricting to '.count($ids).' component ids.');

        return $ids;
    }

    protected function label(Component $component): string
    {
        return mb_substr((string) $component->name, 0, 42);
    }
}
