<?php

namespace App\Http\Controllers;

use App\Models\Build;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\View;

/**
 * THE BUILD PASSPORT - the locked manifest a customer keeps.
 *
 * WHY THIS EXISTS (research 2026-10-13)
 * --------------------------------------
 * A teardown of eight UK custom PC builders found the complaint that dominates the category
 * is NOT price. It is unverifiable build quality:
 *
 *   "If I could be 100% sure of the quality of the build then yeah for the GBP 100 quid odd
 *    extra it would have cost I would have gone for it but I couldn't so I didn't."
 *
 * Not one of those eight publishes a thermal log, a memory-test result, a benchmark or a
 * component manifest. The worst Trustpilot pattern in the category is "the warranty is not the
 * warranty you were sold" - which happens because nobody writes down, at the moment of sale,
 * exactly what was fitted.
 *
 * This is that written record. It is the cheapest differentiator available (the data already
 * exists) and it directly answers the buyer who walked away.
 *
 * WHAT IS DELIBERATELY NOT HERE
 * -----------------------------
 * No benchmark scores. No CPU/GPU temperatures. No memory-test output. NONE of it.
 *
 * That is not an oversight and it is the most important line in this file. We do not record
 * real thermal or benchmark data per build today, so publishing any would be inventing it -
 * which is the single thing that would destroy a 5-star reputation built on honesty. The
 * passport therefore contains ONLY what is genuinely captured: the parts, their part numbers,
 * and the price each one carried at the moment the build was locked.
 *
 * When real per-build test capture exists, this page is where it goes. Until then it says so,
 * rather than implying coverage we do not have.
 */
class BuildPassportController extends Controller
{
    public function show(string $shareSlug): Response
    {
        $build = Build::query()
            ->with(['components.manufacturer', 'components.category'])
            ->where('share_slug', $shareSlug)
            ->first();

        if (! $build) {
            abort(404, 'That build could not be found.');
        }

        // price_snapshot is captured on the pivot at the moment the build was created, so this
        // is what the customer actually paid for, not today's catalogue price. That
        // distinction is the entire value of the document: component prices move daily, and a
        // manifest that silently re-priced itself would be worthless as a record.
        $lines = $build->components
            ->map(fn ($component) => [
                'category' => $this->categoryLabel($component),
                'name' => $component->name,
                'mpn' => $component->mpn ?: null,
                'brand' => $component->manufacturer?->name ?? null,
                'price' => $component->pivot->price_snapshot,
            ])
            ->sortBy(fn ($l) => $this->categoryOrder((string) $l['category']))
            ->values();

        $lockedTotal = $lines->sum(fn ($l) => (float) $l['price']);

        return response()->view('build.passport', [
            'build' => $build,
            'lines' => $lines,
            'lockedTotal' => round((float) $lockedTotal, 2),
            'lockedAt' => $build->created_at,
            // Stated, not implied. See the class docblock.
            'hasTestCapture' => false,
        ]);
    }

    /** Presentation order: the parts a buyer thinks about first, in build order. */
    private function categoryOrder(string $category): int
    {
        $order = ['Processor', 'Graphics Card', 'Motherboard', 'Memory', 'Storage', 'Power Supply', 'Case', 'CPU Cooler', 'Case Fan'];

        $pos = array_search($category, $order, true);

        return $pos === false ? 99 : $pos;
    }

    /**
     * A missing category row must never blank a line in the manifest.
     *
     * The pivot carries the category slug on every row, so the fallback is real data rather
     * than a guess - and an unlabelled part reads to a customer as a mistake in their own
     * paperwork, which is the last impression this document should give.
     */
    private function categoryLabel($component): string
    {
        $name = $component->category?->name;

        if (filled($name)) {
            return $name;
        }

        return ucfirst((string) ($component->pivot->category ?? 'Part'));
    }
}
