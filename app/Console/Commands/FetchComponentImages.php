<?php

namespace App\Console\Commands;

use App\Models\Component;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class FetchComponentImages extends Command
{
    protected $signature = 'components:fetch-images {--force} {--limit=0} {--delay=1.5}';

    protected $description = 'Resolve PCPartPicker product thumbnails for components missing an image_url';

    public function handle(): int
    {
        $query = Component::query()->whereNotNull('source_url');

        if (! $this->option('force')) {
            $query->where(function ($q) {
                $q->whereNull('image_url')->orWhere('image_url', '');
            });
        }

        $total = (clone $query)->count();
        $limit = (int) $this->option('limit');
        $delay = (float) $this->option('delay');

        $builder = clone $query;

        if ($limit > 0) {
            $builder->limit($limit);
        }

        $components = $builder->get();

        $this->info("Resolving thumbnails for {$components->count()} of {$total} components missing an image.");

        $resolved = 0;
        $failed = 0;

        foreach ($components as $component) {
            $imageUrl = $this->fetchOgImage($component->source_url);

            if ($imageUrl !== null) {
                $component->update(['image_url' => $imageUrl]);
                $resolved++;
                $this->line("  ✓ {$component->name}");
            } else {
                $failed++;
                $this->line("  - {$component->name} (no image resolved)");
            }

            if ($delay > 0) {
                usleep((int) round($delay * 1000000));
            }
        }

        $this->info("Done. {$resolved} resolved, {$failed} unresolved.");

        return self::SUCCESS;
    }

    /**
     * Fetch the PCPartPicker product page and extract the og:image (256p
     * thumbnail) URL. Returns null when no image exists. Transient HTTP
     * failures (e.g. rate limiting) are retried with backoff.
     */
    protected function fetchOgImage(string $url): ?string
    {
        $attempts = 3;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $outcome = $this->tryFetch($url);

            if (! $outcome['retryable']) {
                return $outcome['image'];
            }

            if ($attempt < $attempts) {
                usleep($attempt * 1500000);
            }
        }

        return null;
    }

    /**
     * @return array{image: ?string, retryable: bool}
     */
    protected function tryFetch(string $url): array
    {
        try {
            $response = Http::timeout(25)
                ->withoutVerifying()
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
                    'Accept' => 'text/html',
                ])
                ->get($url);
        } catch (\Throwable $e) {
            return ['image' => null, 'retryable' => true];
        }

        if ($response->status() === 429 || $response->status() === 403 || $response->status() === 520 || $response->status() === 503) {
            return ['image' => null, 'retryable' => true];
        }

        if (! $response->successful()) {
            return ['image' => null, 'retryable' => false];
        }

        $html = $response->body();

        if (preg_match('/<meta\s+property="og:image"\s+content="([^"]+)"/i', $html, $m)
            || preg_match('/<meta\s+property="og:image"\s+content=\'([^\']+)\'/i', $html, $m)) {
            $image = html_entity_decode($m[1], ENT_QUOTES);

            if ($image === '') {
                return ['image' => null, 'retryable' => false];
            }

            if (str_starts_with($image, '//')) {
                $image = 'https:' . $image;
            }

            if (str_starts_with($image, 'http://')) {
                $image = 'https://' . substr($image, 7);
            }

            return ['image' => $image, 'retryable' => false];
        }

        return ['image' => null, 'retryable' => false];
    }
}
