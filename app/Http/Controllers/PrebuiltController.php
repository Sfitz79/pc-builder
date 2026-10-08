<?php

namespace App\Http\Controllers;

use App\Models\Component;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * Public pre-built configurator listings.
 *
 * The builds come from database/scraped/prebuilts.json, which is produced by
 * scripts/genie-assemble-prebuilts.php running against the production catalogue.
 * The JSON is the assembler's *protected* evidence output (band + compatibility
 * provenance), so the page re-hydrates every part from the live components table
 * by id and presents the CURRENT catalogue price for each line.
 *
 * Price honesty (brand rule, Boss override 2026-09-29): the total shown is the
 * current catalogue total, NOT a merchant-verified street price - merchant
 * coverage is 0/2708 on production until the Awin lane is joined. The page
 * therefore always renders the fluctuation disclaimer; the listing never claims
 * a locked or verified price.
 */
class PrebuiltController extends Controller
{
    /** @var array<int,array<string,mixed>>|null */
    protected ?array $prebuilts = null;

    protected function prebuilts(): array
    {
        if ($this->prebuilts !== null) {
            return $this->prebuilts;
        }

        $path = database_path('scraped/prebuilts.json');
        if (! is_file($path)) {
            return $this->prebuilts = [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return $this->prebuilts = is_array($decoded) ? $decoded : [];
    }

    /**
     * Builds are order-independent selected part lists: what matters is which
     * categorised slots exist. The builder store keys by category slug.
     */
    protected function categorySlugForPart(string $type): string
    {
        return match (strtolower($type)) {
            'cpu' => 'cpu',
            'cooler' => 'cooler',
            'motherboard' => 'motherboard',
            'gpu' => 'gpu',
            'ram' => 'ram',
            'storage' => 'storage',
            'psu' => 'psu',
            'case' => 'case',
            default => strtolower($type),
        };
    }

    public function index(): View
    {
        $builds = [];

        foreach ($this->prebuilts() as $entry) {
            $parts = [];
            $verifiedTotal = 0.0;

            foreach ($entry['parts'] ?? [] as $part) {
                // Re-hydrate from the LIVE catalogue. A JSON-only read could
                // advertise a name or price that has since been corrected, and
                // that is a brand-integrity failure.
                $component = Component::query()->active()->find($part['id'] ?? null);
                if (! $component) {
                    continue;
                }

                $price = (float) $component->price;
                $verifiedTotal += $price;

                $parts[] = [
                    'type' => $this->categorySlugForPart($part['type']),
                    'typeLabel' => $this->typeLabel($part['type']),
                    'id' => $component->id,
                    'name' => $component->name,
                    'price' => $price,
                ];
            }

            if ($parts === []) {
                continue;
            }

            $builds[] = [
                'name' => $entry['name'],
                'slug' => $this->slugify($entry['name']),
                'socket' => $entry['socket'] ?? null,
                // APU builds must say so on the card, not just in the JSON.
                // These three were missing here, so the storefront silently
                // rendered "Esports only" nowhere even though the assembler had
                // published it - the honest label existed and was discarded
                // one layer up.
                'integrated_graphics' => (bool) ($entry['integrated_graphics'] ?? false),
                'tagline' => $entry['tagline'] ?? null,
                'notes' => $entry['notes'] ?? null,
                'estimated_draw_watts' => $entry['estimated_draw_watts'] ?? null,
                'psu_watts' => $entry['psu_watts'] ?? null,
                'headroom' => $entry['headroom'] ?? null,
                'case_form_factor' => $entry['case_form_factor'] ?? null,
                'parts' => $parts,
                'total' => $verifiedTotal,
                'evidence' => $entry['evidence'] ?? [],
            ];
        }

        return view('prebuilts.index', [
            'builds' => $builds,
            'disclaimer' => 'Component prices fluctuate daily and the total shown is our current catalogue estimate, not a locked quote. Your exact price is confirmed at checkout, and we never charge you more than the price agreed when you order.',
        ]);
    }

    /**
     * Catalog-shaped selection for the builder store's applyBuild().
     */
    public function preset(string $slug): JsonResponse
    {
        $entry = collect($this->prebuilts())
            ->first(fn (array $build) => $this->slugify($build['name']) === $slug);

        if (! $entry) {
            return response()->json([
                'success' => false,
                'error' => 'Unknown pre-built',
            ], 404);
        }

        $selection = [];

        foreach ($entry['parts'] ?? [] as $part) {
            $component = Component::query()->active()->find($part['id'] ?? null);
            if (! $component) {
                continue;
            }

            $selection[$this->categorySlugForPart($part['type'])] = [
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
                'complete' => true,
                // An APU build has no discrete GPU: the CPU's integrated
                // graphics drive the display. The client needs to know, because
                // checkout refuses to create an order while a required category
                // is missing, and 'gpu' is in that list. Without this flag the
                // build loads with a blank GPU slot and reads as incomplete, so
                // the customer cannot buy a machine that is deliberately
                // complete.
                'integrated_graphics' => (bool) ($entry['integrated_graphics'] ?? false),
                'components' => $selection,
            ],
        ]);
    }

    /**
     * URL slug for a build name.
     *
     * Separators are trimmed from BOTH ends. Without this,
     * "Esports 1080p APU (No Dedicated GPU)" produced
     * "esports-1080p-apu-no-dedicated-gpu-" because the closing bracket became a
     * trailing hyphen. The storefront link and the route agreed, so it worked,
     * but any parenthesised name produced a malformed URL.
     */
    protected function slugify(string $name): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($name)) ?? '');

        return trim($slug, '-');
    }

    protected function typeLabel(string $type): string
    {
        return match (strtolower($type)) {
            'cpu' => 'Processor',
            'cooler' => 'CPU Cooler',
            'motherboard' => 'Motherboard',
            'gpu' => 'Graphics Card',
            'ram' => 'Memory',
            'storage' => 'Storage',
            'psu' => 'Power Supply',
            'case' => 'Case',
            default => ucfirst($type),
        };
    }
}