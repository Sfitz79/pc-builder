<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Component;
use App\Services\AwinFeedService;
use App\Services\MerchantFeedMatcher;
use App\Services\PriceIntegrityService;
use Illuminate\Console\Command;

/**
 * Report price-evidence coverage and, optionally, ingest a merchant feed.
 *
 * THE DEFAULT IS A DRY RUN. Nothing is written without --write, because the
 * whole point of this command is to answer "how much of the catalogue can we
 * evidence from a real merchant feed?" BEFORE anything is stamped. The live
 * catalogue currently has 0 of 2,708 rows with a price_checked_at stamp, and
 * PriceIntegrityService only withholds stale prices once that coverage exists
 * - so the coverage number this prints is the go/no-go input for turning
 * PRICE_INTEGRITY_ENFORCE_FRESHNESS on.
 *
 * Safety rails on --write:
 *   - only matches at or above --min-confidence (default: high, i.e. GTIN only)
 *   - only rows the feed says are in stock AND carry a real price
 *   - a price that moved more than --max-delta (default 50%) from the
 *     catalogue price is reported as suspicious and NOT written, because a
 *     5x jump is far more likely a mis-match than a real market move
 */
class IngestMerchantFeed extends Command
{
    protected $signature = 'prices:ingest-feed
        {file? : Path to an Awin-format product feed CSV (optionally .gz)}
        {--download : Fetch the live feed from Awin instead of reading a file (needs AWIN_FEED_API_KEY)}
        {--mid= : Comma-separated Awin merchant (advertiser) ids, required for --download}
        {--list : List the feeds this Awin publisher can access (needs AWIN_FEED_API_KEY), then exit}
        {--write : Actually stamp components through markChecked (default: report only)}
        {--min-confidence=high : Lowest confidence to write: high|medium|low}
        {--max-delta=0.5 : Reject a feed price differing from the catalogue price by more than this fraction}
        {--category= : Limit to one category slug}
        {--limit=0 : Cap components considered (0 = no limit)}';

    protected $description = 'Report price-evidence coverage and optionally ingest an Awin merchant product feed';

    /** Set when a feed was explicitly requested but could not be produced. */
    private ?string $feedError = null;

    public function handle(AwinFeedService $awin, MerchantFeedMatcher $matcher, PriceIntegrityService $integrity): int
    {
        if ($this->option('list')) {
            return $this->listFeeds($awin);
        }

        $components = $this->components();
        if ($components->isEmpty()) {
            $this->error('No active components matched the selection.');

            return self::FAILURE;
        }

        $this->reportCoverage($components, $integrity);

        $feed = $this->loadFeed($awin);
        if ($feed === null) {
            if ($this->feedError !== null) {
                // A feed was explicitly asked for and could not be produced.
                // Reporting "coverage only, all good" here would hide a broken
                // download behind a zero exit code.
                return self::FAILURE;
            }

            // Coverage only. This is a legitimate run: it is the measurement the
            // boss needs to decide whether to pursue a feed at all.
            $this->newLine();
            $this->line('No feed given (pass a file, or --download --mid=...). Coverage report only.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('=== feed ===');
        $this->line('  rows: '.$feed->count());
        $inStock = $feed->filter(fn ($r) => ($r['in_stock'] ?? false) && ($r['price'] ?? null) !== null)->count();
        $this->line('  in stock with a price: '.$inStock);
        $merchants = $feed->filter(fn ($r) => ($r['merchant_name'] ?? $r['merchant_id'] ?? null) !== null)
            ->pluck('merchant_name')->filter()->unique()->values();
        $this->line('  merchants: '.($merchants->isEmpty() ? '(none named)' : $merchants->implode(', ')));

        $result = $matcher->match($feed, $components);

        $this->newLine();
        $this->line('=== match (identity) ===');
        $this->line('  components considered : '.$result['total_components']);
        $this->line('  matched by GTIN (high): '.$result['counts']['gtin']);
        $this->line('  matched by MPN (med) : '.$result['counts']['mpn']);
        $this->line('  matched by name (low) : '.$result['counts']['name']);
        $this->line('  ambiguous names dropped: '.$result['ambiguous_names']);
        $this->line('  components unmatched  : '.$result['unmatched_components']);
        $this->line('  feed rows unused      : '.$result['unmatched_rows']);

        $usable = $this->usable($result, $feed);
        $this->line('  matched AND in stock with a price: '.$usable->count());
        $this->line(
            '  => potential coverage of the catalogue: '
            .round($usable->count() / max(1, $result['total_components']) * 100, 1).'%'
        );

        $write = (bool) $this->option('write');
        $minConfidence = (string) $this->option('min-confidence');
        if (! in_array($minConfidence, MerchantFeedMatcher::CONFIDENCE_ORDER, true)) {
            $this->error("Unknown --min-confidence '{$minConfidence}'. Use high, medium or low.");

            return self::FAILURE;
        }

        $this->newLine();
        if (! $write) {
            $this->line('DRY RUN - nothing written. Re-run with --write to stamp (min-confidence='.$minConfidence.').');

            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        $this->line('=== write (min-confidence='.$minConfidence.') ===');

        $written = 0;
        $skipped = 0;
        $suspicious = 0;
        $seen = 0;

        foreach ($usable as $entry) {
            if ($limit > 0 && $seen >= $limit) {
                break;
            }
            $seen++;

            $component = $components->get($entry['component_id']);
            if ($component === null) {
                // A match we cannot resolve to a component is a broken lookup,
                // not a reason to quietly count a skip. That exact bug is why
                // "written : 0" once reported success with every match skipped.
                $this->error(sprintf(
                    '  BROKEN LOOKUP  matched component id %d is not in the selected set - not written',
                    $entry['component_id']
                ));
                $skipped++;
                continue;
            }

            $row = $entry['row'];
            $price = (float) $row['price'];

            $delta = abs($price - (float) $component->price) / max(1.0, (float) $component->price);
            if ($delta > (float) $this->option('max-delta')) {
                $suspicious++;
                $this->line(sprintf(
                    '  SUSPECT  %-6s %-40s feed GBP %8.2f vs catalogue GBP %8.2f (%+.0f%%) - not written',
                    $component->id,
                    mb_substr((string) $component->name, 0, 40),
                    $price,
                    (float) $component->price,
                    ($price - (float) $component->price) / max(1.0, (float) $component->price) * 100
                ));
                continue;
            }

            $integrity->markChecked(
                $component,
                $price,
                ($row['in_stock'] ?? false) ? 1 : 0,
                $row['merchant_deep_link'] ?? $row['deep_link'] ?? null
            );
            $written++;
        }

        $this->newLine();
        $this->line('  written  : '.$written);
        $this->line('  suspicious (price jump, not written): '.$suspicious);
        $this->line('  skipped  : '.$skipped);
        $this->line('  Now re-run prices:ingest-feed with no feed to see the new coverage before');
        $this->line('  considering PRICE_INTEGRITY_ENFORCE_FRESHNESS=true.');

        return self::SUCCESS;
    }

    /**
     * Matches that are strong enough to write AND actually buyable.
     *
     * @param  array{matches: array<int, array{index: int, row: array<string, mixed>, key: string, confidence: string}>}  $result
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $feed
     * @return \Illuminate\Support\Collection<int, array{component_id: int, confidence: string, row: array<string, mixed>}>
     */
    private function usable(array $result, \Illuminate\Support\Collection $feed)
    {
        $min = (string) $this->option('min-confidence');
        $allow = array_slice(
            MerchantFeedMatcher::CONFIDENCE_ORDER,
            0,
            (int) array_search($min, MerchantFeedMatcher::CONFIDENCE_ORDER, true) + 1
        );

        $out = collect();
        foreach ($result['matches'] as $componentId => $match) {
            if (! in_array($match['confidence'], $allow, true)) {
                continue;
            }
            $row = $feed->get($match['index']);
            if (! is_array($row) || ($row['in_stock'] ?? false) !== true || ($row['price'] ?? null) === null) {
                continue;
            }
            $out->push(['component_id' => (int) $componentId, 'confidence' => $match['confidence'], 'row' => $row]);
        }

        return $out;
    }

    /**
     * The active components to consider, as light models.
     *
     * @return \Illuminate\Support\Collection<int, Component>
     */
    private function components()
    {
        // Eager-load the category (the coverage table is grouped by it, and
        // without this it is one query per component across the whole
        // catalogue), and key the result BY ID: the write loop looks components
        // up by component id, and an unkeyed collection is keyed 0..n-1, so
        // every get() missed and every write silently became a skip.
        $query = Component::query()->with('category')->where('active', true);

        if (($slug = $this->option('category')) !== null) {
            $category = Category::query()->where('slug', $slug)->first();
            if ($category === null) {
                $this->error("No category with slug '{$slug}'.");

                return collect();
            }
            $query->where('category_id', $category->id);
        }

        if (($limit = (int) $this->option('limit')) > 0) {
            $query->limit($limit);
        }

        return $query->get()->keyBy('id');
    }

    /**
     * The evidence coverage of the live catalogue - the number that decides
     * whether freshness can be enforced yet.
     *
     * @param  \Illuminate\Support\Collection<int, Component>  $components
     */
    private function reportCoverage(\Illuminate\Support\Collection $components, PriceIntegrityService $integrity): void
    {
        $verified = 0;
        $fresh = 0;
        $unsourced = 0;

        $byCategory = [];

        foreach ($components as $component) {
            $checked = $component->price_checked_at !== null;
            $verified += $checked ? 1 : 0;

            if ($checked && $integrity->freshness($component)['fresh']) {
                $fresh++;
            }

            if (trim((string) $component->source_url) === '') {
                $unsourced++;
            }

            $slug = $component->category?->slug ?? ('cat-'.$component->category_id);
            $byCategory[$slug] ??= ['total' => 0, 'verified' => 0, 'fresh' => 0];
            $byCategory[$slug]['total']++;
            $byCategory[$slug]['verified'] += $checked ? 1 : 0;
            if ($checked && $integrity->freshness($component)['fresh']) {
                $byCategory[$slug]['fresh']++;
            }
        }

        $total = $components->count();

        $this->line('=== catalogue price evidence ===');
        $this->line('  active components : '.$total);
        $this->line('  verified (price_checked_at set): '.$verified.'  ('.round($verified / max(1, $total) * 100, 1).'%)');
        $this->line('  fresh (within '.PriceIntegrityService::FRESH_DAYS.' days)      : '.$fresh);
        $this->line('  no source_url (unquotable) : '.$unsourced);
        $this->line('  enforce_freshness currently : '.(PriceIntegrityService::enforcesFreshness() ? 'ON' : 'OFF'));

        $rows = [];
        foreach ($byCategory as $slug => $counts) {
            $rows[] = [
                $slug,
                $counts['total'],
                $counts['verified'],
                $counts['fresh'],
                round($counts['verified'] / max(1, $counts['total']) * 100, 1).'%',
            ];
        }

        $this->newLine();
        $this->table(['category', 'total', 'verified', 'fresh', 'coverage'], $rows);
    }

    /**
     * Load the feed: from a local file, or downloaded from Awin.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>|null
     */
    private function loadFeed(AwinFeedService $awin)
    {
        $file = $this->argument('file');

        if ($file === null && ! $this->option('download')) {
            return null;
        }

        if ($this->option('download')) {
            $mid = (string) $this->option('mid');
            if (trim($mid) === '') {
                $this->feedError = '--download needs --mid=<advertiser ids>; downloading every feed at once is far too large. '
                    .'Find your ids with: prices:ingest-feed --list';
                $this->error($this->feedError);

                return null;
            }

            if (! $awin->configured()) {
                $this->feedError = 'Awin feed api key is not configured (AWIN_FEED_API_KEY) - owner-held credential.';
                $this->error($this->feedError);

                return null;
            }

            $this->line('Downloading Awin feed for mid='.$mid.' ...');
            try {
                $csv = $awin->downloadFeed(['mid' => $mid]);
            } catch (\RuntimeException $e) {
                $this->feedError = 'Feed download failed: '.$e->getMessage();
                $this->error($this->feedError);

                return null;
            }
        } else {
            $contents = @file_get_contents((string) $file);
            if ($contents === false) {
                $this->feedError = "Could not read feed file: {$file}";
                $this->error($this->feedError);

                return null;
            }
            $csv = $awin->maybeGunzip($contents);
        }

        try {
            return collect($awin->parseFeed($csv));
        } catch (\RuntimeException $e) {
            $this->feedError = 'Feed parse failed: '.$e->getMessage();
            $this->error($this->feedError);

            return null;
        }
    }

    /**
     * Print the feeds this publisher can download. This is how an advertiser
     * (merchant) id is discovered, which --download then needs.
     */
    private function listFeeds(AwinFeedService $awin): int
    {
        if (! $awin->configured()) {
            $this->error('Awin feed api key is not configured (AWIN_FEED_API_KEY) - owner-held credential.');

            return self::FAILURE;
        }

        try {
            $rows = $awin->feedList();
        } catch (\RuntimeException $e) {
            $this->error('Feed list failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($rows === []) {
            $this->line('The feed list was empty.');

            return self::SUCCESS;
        }

        // Columns are whatever the publisher is served, so print them verbatim
        // rather than assuming a fixed header (Rule 4: never assume a shape).
        $headers = array_keys($rows[0]);
        $this->table($headers, array_map(fn ($r) => array_map(
            fn ($h) => (string) ($r[$h] ?? ''),
            $headers
        ), $rows));

        return self::SUCCESS;
    }
}
