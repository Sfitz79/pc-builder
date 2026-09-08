<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BuyWhereService;
use App\Services\CompatibilityCheckerService;
use App\Services\PriceApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PriceComparisonController extends Controller
{
    protected BuyWhereService $buyWhere;
    protected PriceApiService $priceApi;
    protected CompatibilityCheckerService $compatibilityChecker;

    public function __construct(
        BuyWhereService $buyWhere,
        PriceApiService $priceApi,
        CompatibilityCheckerService $compatibilityChecker
    ) {
        $this->buyWhere = $buyWhere;
        $this->priceApi = $priceApi;
        $this->compatibilityChecker = $compatibilityChecker;
    }

    /**
     * Compare prices across multiple sources
     */
    public function comparePrices(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => 'required|string|max:200',
            'sources' => 'array|in:amazon,ebay,google_shopping',
        ]);

        try {
            $sources = $validated['sources'] ?? ['amazon', 'ebay'];
            $results = $this->priceApi->comparePrices($validated['query'], $sources);

            return response()->json([
                'success' => true,
                'query' => $validated['query'],
                'sources' => $sources,
                'data' => $results,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Search BuyWhere product catalog
     */
    public function searchBuyWhere(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => 'required|string|max:200',
            'limit' => 'integer|min:1|max:50',
        ]);

        try {
            $results = $this->buyWhere->searchProducts(
                $validated['query'],
                ['limit' => $validated['limit'] ?? 20]
            );

            return response()->json([
                'success' => true,
                'source' => 'buywhere',
                'count' => count($results),
                'data' => $results,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check compatibility of selected parts
     */
    public function checkCompatibility(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'parts' => 'required|array',
        ]);

        try {
            $result = $this->compatibilityChecker->checkCompatibility($validated['parts']);

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get build summary with pricing
     */
    public function getBuildSummary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'parts' => 'required|array',
        ]);

        try {
            $summary = $this->compatibilityChecker->generateSummary($validated['parts']);

            return response()->json([
                'success' => true,
                'data' => $summary,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
