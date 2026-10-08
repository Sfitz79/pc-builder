<?php

namespace App\Http\Controllers;

use App\Models\Component;
use App\Services\BuildPolicyGate;
use Illuminate\Http\Request;

/**
 * The featured systems range.
 *
 * Reads database/scraped/featured-builds.json, which is produced by
 * scripts/genie-assemble-featured.php from production data under
 * config/build_policy.php. Every part is re-resolved against the live catalogue
 * here, so a build can never be shown with a stale or withdrawn part.
 *
 * The three axes are closed - usage, resolution, tier - so the filter controls
 * cannot offer a combination the taxonomy does not contain. That matters: an
 * open dropdown would eventually offer "4K / low / streaming", which is not a
 * product we build.
 */
class FeaturedController extends Controller
{
    public function index(Request $request)
    {
        $path = database_path('scraped/featured-builds.json');

        if (! is_file($path)) {
            return response()->view('featured.index', [
                'builds' => [],
                'taxonomy' => (array) config('featured_builds'),
                'usage' => $request->get('usage'),
                'resolution' => $request->get('resolution'),
                'tier' => $request->get('tier'),
                'empty' => true,
            ]);
        }

        $raw = json_decode((string) file_get_contents($path), true);
        $builds = [];
        $dropped = 0;

        foreach ($raw['builds'] ?? [] as $entry) {
            $parts = [];
            $total = 0.0;

            foreach ($entry['parts'] as $part) {
                $component = Component::query()->active()->find($part['id'] ?? null);
                if (! $component) {
                    continue;
                }
                $total += (float) $component->price;
                $parts[] = [
                    'type' => $part['type'],
                    'id' => $component->id,
                    'name' => $component->name,
                    'price' => (float) $component->price,
                    'image' => $component->image_url,
                ];
            }

            // A build missing any part cannot be bought, so it is not shown.
            if (count($parts) !== count($entry['parts'])) {
                $dropped++;

                continue;
            }

            $builds[] = $entry + [
                'parts' => $parts,
                'verified_total' => round($total, 2),
            ];
        }

        $taxonomy = (array) config('featured_builds');

        // Filters are validated against the taxonomy, not accepted verbatim.
        $usage = $this->cleanParam($request->get('usage'), array_keys($taxonomy['axes']['usage']));
        $resolution = $this->cleanParam($request->get('resolution'), $taxonomy['axes']['resolution']);
        $tier = $this->cleanParam($request->get('tier'), array_keys($taxonomy['axes']['tier']));

        $filtered = array_values(array_filter($builds, function (array $b) use ($usage, $resolution, $tier) {
            return ($usage === null || $b['tags']['usage'] === $usage)
                && ($resolution === null || $b['tags']['resolution'] === $resolution)
                && ($tier === null || $b['tags']['tier'] === $tier);
        }));

        if ($request->wantsJson()) {
            return response()->json(['builds' => $filtered, 'count' => count($filtered)]);
        }

        return response()->view('featured.index', [
            'builds' => $filtered,
            'taxonomy' => $taxonomy,
            'usage' => $usage,
            'resolution' => $resolution,
            'tier' => $tier,
            'total_available' => count($builds),
            'dropped' => $dropped,
            'empty' => false,
        ]);
    }

    /**
     * Accept a filter value only if it exists in the taxonomy. An unknown value
     * is discarded rather than passed through, so a hand-edited query string
     * cannot put the page into a state the taxonomy does not describe.
     */
    protected function cleanParam(?string $value, array $allowed): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        foreach ($allowed as $candidate) {
            if (strcasecmp((string) $candidate, $value) === 0) {
                return (string) $candidate;
            }
        }

        return null;
    }

    /**
     * Load one featured system into the builder.
     *
     * Shape matches the prebuilt preset endpoint so builder.js needs no second
     * code path: a featured system and a pre-built apply through the same
     * applyBuild() call.
     */
    public function apply(string $slug)
    {
        $path = database_path('scraped/featured-builds.json');
        if (! is_file($path)) {
            return response()->json(['success' => false, 'error' => 'Featured range unavailable'], 404);
        }

        $raw = json_decode((string) file_get_contents($path), true);
        $entry = null;
        foreach ($raw['builds'] ?? [] as $b) {
            if (($b['slug'] ?? null) === $slug) {
                $entry = $b;
                break;
            }
        }

        if (! $entry) {
            return response()->json(['success' => false, 'error' => 'Unknown system'], 404);
        }

        $slugFor = fn (string $type) => strtolower(
            preg_replace('/[^a-z0-9]+/i', '', $type) ?? ''
        );

        $selection = [];
        foreach ($entry['parts'] as $part) {
            $component = Component::query()->active()->find($part['id'] ?? null);
            if (! $component) {
                continue;
            }
            $selection[$slugFor((string) $part['type'])] = [
                'id' => $component->id,
                'name' => $component->name,
                'price' => (float) $component->price,
                'socket' => $component->socket,
                'chipset' => $component->chipset,
                'wattage' => $component->wattage,
                'stock' => $component->stock,
                'specs' => is_array($component->specs) ? $component->specs : [],
            ];
        }

        return response()->json([
            'success' => true,
            'build' => [
                'name' => $entry['name'],
                'summary' => $entry['summary'] ?? null,
                'tags' => $entry['tags'] ?? null,
                'complete' => true,
                'integrated_graphics' => (bool) ($entry['integrated_graphics'] ?? false),
                'components' => $selection,
            ],
        ]);
    }
}