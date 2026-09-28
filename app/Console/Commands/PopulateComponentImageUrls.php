<?php

namespace App\Console\Commands;

use App\Models\Component;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Populate components.image_url from the local component image cache.
 *
 * WHY THIS EXISTS: the business has no third-party CDN account and does not need
 * one. Vercel serves everything in public/ from its own global edge network, so
 * public/img/components/<id>.jpg is already CDN-hosted at
 * <app-url>/img/components/<id>.jpg. The files are named by component id, so the
 * mapping is exact - no fuzzy matching, no guessing, no fabricated URLs.
 *
 * This is strictly better than the old remote vendor URLs: nothing is hotlinked,
 * a vendor cannot rot or rate-limit us, and we control the file.
 *
 * SAFEGUARDS:
 *  - Dry-run unless --apply is passed. A mass write to a live storefront should
 *    never be the default.
 *  - Only sets image_url when the file actually exists on disk. A component with
 *    no cached photo is left null rather than pointed at a 404.
 *  - Never clears an image_url that is already set unless --force is given, so a
 *    good vendor URL is not silently replaced by a local copy.
 *  - Adds a ?v= cache-busting token from the file mtime, because Vercel serves
 *    static assets with a long immutable cache; without it, replacing a photo
 *    would keep serving the old one.
 */
class PopulateComponentImageUrls extends Command
{
    protected $signature = 'components:populate-image-urls
        {--apply : Actually write. Without this it only reports.}
        {--force : Also overwrite image_url values that are already set.}
        {--include-inactive : Also stamp components that are not active.}';

    protected $description = 'Point components.image_url at the Vercel-served local image cache';

    public function handle(): int
    {
        $dir = public_path('img/components');
        if (! is_dir($dir)) {
            $this->error("No image cache at {$dir}");

            return self::FAILURE;
        }

        $base = rtrim((string) config('app.url'), '/').'/img/components';
        $apply = (bool) $this->option('apply');
        $force = (bool) $this->option('force');
        $includeInactive = (bool) $this->option('include-inactive');

        // Build the id => file map once, keyed on the bare numeric stem so
        // "100.jpg" and "100.JPG" cannot both land in the same slot.
        $files = [];
        foreach (scandir($dir) ?: [] as $f) {
            if (preg_match('/^(\d+)\.jpe?g$/i', $f, $m)) {
                $files[(int) $m[1]] = $f;
            }
        }

        $this->info(sprintf('image cache: %d file(s) in %s', count($files), $dir));
        $this->info("target base:  {$base}");
        $this->line($apply ? '<fg=yellow>MODE: APPLY (writing)</>' : '<fg=cyan>MODE: DRY-RUN (no writes)</>');

        $q = Component::query();
        if (! $includeInactive) {
            $q->where('active', true);
        }

        $stamp = $updatable = $skippedSet = $skippedNoFile = 0;
        $rows = [];

        $q->orderBy('id')->chunk(300, function ($components) use (
            $files, $base, $force, &$stamp, &$updatable, &$skippedSet, &$skippedNoFile, &$rows
        ) {
            foreach ($components as $c) {
                $file = $files[(int) $c->id] ?? null;
                if ($file === null) {
                    $skippedNoFile++;

                    continue;
                }

                $existing = (string) $c->image_url;
                if ($existing !== '' && ! $force) {
                    $skippedSet++;

                    continue;
                }

                $mtime = @filemtime($dir.'/'.$file) ?: 0;
                $url = $base.'/'.$file.($mtime ? '?v='.$mtime : '');

                $stamp++;
                $rows[] = ['id' => $c->id, 'image_url' => $url];

                if ($apply && $c->image_url !== $url) {
                    $c->image_url = $url;
                    $c->save();
                    $updatable++;
                }
            }
        });

        $this->newLine();
        $this->line("components eligible      : ".($stamp + $skippedNoFile + $skippedSet));
        $this->line("would stamp / stamped  : {$stamp}".($apply ? " (wrote {$updatable})" : ''));
        $this->line("already had image_url  : {$skippedSet} (kept - pass --force to replace)");
        $this->line("no file for component  : {$skippedNoFile} (left null, not pointed at a 404)");

        if (! $apply && $stamp) {
            $this->newLine();
            $this->line('sample of what would be set:');
            foreach (array_slice($rows, 0, 5) as $r) {
                $this->line('  #'.$r['id'].'  '.$r['image_url']);
            }
            $this->newLine();
            $this->comment('Re-run with --apply to write.');
        }

        // Components in the cache that are not active components at all: these
        // are usually rows we have deactivated, and a stale file for them is dead
        // weight in the deployment. Worth knowing, not worth deleting silently.
        $ids = DB::table('components')->pluck('id')->map(fn ($i) => (int) $i)->all();
        $orphans = array_diff(array_keys($files), $ids);
        if ($orphans) {
            $this->newLine();
            $this->warn(sprintf('%d image(s) have no matching component row: %s',
                count($orphans), implode(', ', array_slice($orphans, 0, 20))));
        }

        return self::SUCCESS;
    }
}
