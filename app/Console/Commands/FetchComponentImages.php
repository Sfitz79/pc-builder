<?php

namespace App\Console\Commands;

use App\Models\Component;
use App\Services\ComponentImageService;
use Illuminate\Console\Command;

/**
 * Resolve product imagery for catalogue components.
 *
 * Rebuilt 2026-09-28. The previous version fetched the PCPartPicker product page
 * with a plain HTTP client, which Cloudflare blocks, so it resolved almost
 * nothing; it also passed withoutVerifying() to hide a broken CA chain, which is
 * now fixed properly in php.ini. Images are fetched through the same Byparr
 * (Camoufox) transport the price pass uses, the full-size asset is preferred
 * over PCPP's 256x256 og thumbnail, and a copy is cached under
 * public/img/components.
 */
class FetchComponentImages extends Command
{
    protected $signature = 'components:fetch-images
        {--force : re-resolve components that already have an image}
        {--ids-file= : newline or comma separated component ids to restrict to}
        {--limit=0 : stop after this many components (0 = no limit)}
        {--delay=1.5 : seconds to wait between components}
        {--local : point image_url at the local cache instead of the CDN url}
        {--byparr= : Byparr base url, defaults to services.byparr.url}
        {--attempts=3 : tries per component before giving up}
        {--dry-run : resolve and report, write nothing}';

    protected $description = 'Resolve PCPartPicker product images (full-size, cached locally) for components missing an image_url';

    public function handle(ComponentImageService $images): int
    {
        if ($byparr = $this->option('byparr')) {
            config(['services.byparr.url' => $byparr]);
        }

        if (! $images->getPcpp()->configured()) {
            $this->error('Byparr is not configured. Set BYPARR_URL or pass --byparr.');
            $this->error('Start it with: pc-builder\\scripts\\launch-byparr.ps1');

            return self::FAILURE;
        }

        $query = Component::query()->whereNotNull('source_url')->where('source_url', '!=', '');

        if (! $this->option('force')) {
            $query->where(function ($q) {
                $q->whereNull('image_url')->orWhere('image_url', '');
            });
        }

        $ids = $this->idsFromFile();

        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        $total = (clone $query)->count();
        $limit = (int) $this->option('limit');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $components = $query->get();

        $this->info(sprintf(
            'Resolving images for %d of %d components. Dry run: %s',
            $components->count(),
            $total,
            $this->option('dry-run') ? 'yes' : 'no'
        ));

        $resolved = 0;
        $failed = 0;
        $upgraded = 0;
        $delay = (float) $this->option('delay');
        $useLocal = (bool) $this->option('local');
        $dryRun = (bool) $this->option('dry-run');
        $attempts = $this->attempts();

        foreach ($components as $component) {
            $previous = (string) $component->image_url;
            $result = null;
            $error = null;

            // Byparr occasionally returns a truncated/unparseable body (large
            // product page plus a cold Camoufox). That is transient, so retry
            // with backoff rather than recording a permanent miss.
            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                try {
                    $result = $images->resolveFor($component, $useLocal);
                    $error = null;
                    break;
                } catch (\Throwable $e) {
                    $error = $e->getMessage();

                    if ($attempt < $attempts) {
                        $this->line(sprintf(
                            '    . %s attempt %d/%d failed (%s), retrying',
                            $this->label($component),
                            $attempt,
                            $attempts,
                            $error
                        ));
                        sleep($attempt * 2);
                    }
                }
            }

            if ($result === null) {
                $failed++;
                $this->line(sprintf('  x %-46s %s', $this->label($component), $error ?? 'unknown error'));

                if ($delay > 0) {
                    usleep((int) round($delay * 1000000));
                }

                continue;
            }

            $component->image_url = $result['url'];

            if (! $dryRun) {
                $component->save();
            }

            $resolved++;

            $wasSmall = str_contains($previous, '.256p.') || str_contains($previous, 'm.media-amazon.com');

            if ($wasSmall && $result['width'] > 256) {
                $upgraded++;
            }

            $this->line(sprintf(
                '  + %-46s %dx%d %s%s',
                $this->label($component),
                $result['width'],
                $result['height'],
                $result['cached'] !== null ? 'cached '.$result['cached'] : 'no-cache',
                $dryRun ? '  [dry]' : ''
            ));

            if ($delay > 0) {
                usleep((int) round($delay * 1000000));
            }
        }

        $this->info(sprintf(
            'Done. %d resolved (%d upgraded from small images), %d failed.',
            $resolved,
            $upgraded,
            $failed
        ));

        return self::SUCCESS;
    }

    protected function attempts(): int
    {
        return max(1, (int) $this->option('attempts'));
    }

    /**
     * @return list<int>|null null means "no id filter"
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

        $raw = (string) file_get_contents($path);

        $ids = array_values(array_unique(array_filter(array_map(
            'intval',
            preg_split('/[\s,]+/', $raw) ?: []
        ))));

        if ($ids === []) {
            $this->error("ids file contained no usable ids: {$path}");

            return null;
        }

        $this->info('Restricting to '.count($ids).' component ids.');

        return $ids;
    }

    protected function label(Component $component): string
    {
        return mb_substr((string) $component->name, 0, 46);
    }
}
