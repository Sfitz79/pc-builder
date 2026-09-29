<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AIRecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Server-to-server build recommendation API.
 *
 * Powers the Studio's auto-reply engine: given a lead message's budget /
 * purpose / resolution, this returns ONE ready-made system (component names,
 * complete all-in price only — no per-part prices, honouring the hidden-margin
 * mandate). Used by the Genie to post a real build as a reply to buyer intent.
 *
 * Auth: if BUILDER_GENIE_TOKEN is set in the environment, the request must
 * carry it in the X-Genie-Token header. When unset (local dev) the endpoint
 * mirrors the public /builder/ai behaviour.
 */
class BuildRecommendationController extends Controller
{
    public function __construct(
        protected AIRecommendationService $recommendations,
    ) {}

    public function recommend(Request $request): JsonResponse
    {
        // Accept the site's own public vocabulary.
        //
        // GET /builder/bands publishes its band keys as "1080p", "1440p" and
        // "4k", but this endpoint validated against "1080P", "1440P" and "4K".
        // A client that read the bands and passed the key straight through hit
        // a failed validation, and a non-JSON 302 redirect back to the
        // homepage instead of a build - so the two halves of the same feature
        // disagreed on how a resolution is spelled.
        if ($request->has('resolution') && is_string($request->input('resolution'))) {
            $request->merge([
                'resolution' => strtoupper(trim($request->input('resolution'))),
            ]);
        }

        $expected = env('BUILDER_GENIE_TOKEN');

        if ($expected !== null && $expected !== '' && ! hash_equals((string) $expected, (string) $request->header('X-Genie-Token', ''))) {
            return response()->json(['message' => 'Unauthorised.'], 401);
        }

        $data = $request->validate([
            'budget' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'purpose' => ['nullable', Rule::in('gaming', 'streaming', 'creation', 'ai')],
            'resolution' => ['nullable', Rule::in(['1080P', '1440P', '4K'])],
            'pick' => ['nullable', Rule::in(['auto', 'budget', 'ideal'])],
        ]);

        $asked = isset($data['budget']) ? (float) $data['budget'] : 0.0;
        $purpose = $data['purpose'] ?? null;
        $resolution = $data['resolution'] ?? '1440P';
        $pick = $data['pick'] ?? 'auto';

        // Clamp a below-minimum budget here too (boss directive 2026-09-28).
        //
        // This endpoint posts real builds into the auto-reply engine, so a lead
        // saying "I have about GBP 700" must NOT receive a GBP 1,362 machine
        // presented as though it met their budget - that is the exact
        // overclaim the 5-star reputation cannot afford. The budget is raised
        // to the honest floor and the caller is told what happened, so the
        // reply can lead with the part-new/part-used route instead.
        $notice = $this->recommendations->budgetNotice($asked, $resolution);

        $budget = ($notice !== null && $notice['raised'])
            ? (float) $notice['min']
            : $asked;

        // auto = the budget-sensible build when a budget is given, else the ideal.
        $want = $pick === 'auto'
            ? ($budget > 0 ? 'budget' : 'ideal')
            : $pick;

        $both = $this->recommendations->recommendBoth($budget, $purpose, $resolution, null);

        $build = $both[$want] ?? $both['ideal'] ?? [];
        $delivery = (float) config('pricing.build_delivery', 0);
        $total = round((float) ($build['total'] ?? 0.0), 2);

        return response()->json([
            'ok' => true,
            'source' => $want,
            'mode' => $build['mode'] ?? $want,
            'budget' => $asked,
            'budget_used' => $budget,
            'purpose' => $purpose,
            'resolution' => $resolution,
            'notice' => $notice,
            'bands' => $this->recommendations->workableBands(),
            'build' => [
                'components' => collect($build['components'] ?? [])
                    ->map(fn (array $item, string $category) => [
                        'category' => $category,
                        'id' => (int) ($item['id'] ?? 0),
                        'name' => (string) ($item['name'] ?? ''),
                    ])
                    ->values()
                    ->all(),
                'total' => $total,
                'delivery' => $delivery,
                'all_in_total' => round($total + $delivery, 2),
                'remaining' => round((float) ($build['remaining'] ?? 0.0), 2),
                'complete' => (bool) ($build['complete'] ?? false),
                'apu_warning' => (bool) ($build['apuWarning'] ?? false),
                'cheapest_option' => (bool) ($build['cheapestOption'] ?? false),
                'over_budget' => round((float) ($build['overBudget'] ?? 0.0), 2),
                'explanation' => $build['explanation'] ?? null,
                'payments' => $build['payments'] ?? ['card', 'paypal_pay_in_3'],
                'rationale' => $build['ai']['rationale'] ?? null,
            ],
        ]);
    }
}