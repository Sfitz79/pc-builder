<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PcComponentScraperService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PcComponentController extends Controller
{
    protected PcComponentScraperService $scraper;

    public function __construct(PcComponentScraperService $scraper)
    {
        $this->scraper = $scraper;
    }

    /**
     * Search PC components on Amazon
     */
    public function searchAmazon(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => 'required|string|max:200',
            'max_items' => 'integer|min:1|max:100',
            'min_price' => 'numeric|min:0',
            'max_price' => 'numeric|min:0',
        ]);

        try {
            $results = $this->scraper->scrapeAmazon(
                $validated['query'],
                [
                    'max_items' => $validated['max_items'] ?? 20,
                    'min_price' => $validated['min_price'] ?? 0,
                    'max_price' => $validated['max_price'] ?? 10000,
                ]
            );

            return response()->json([
                'success' => true,
                'source' => 'amazon',
                'query' => $validated['query'],
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
     * Search PC components on Newegg
     */
    public function searchNewegg(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => 'required|string|max:200',
            'max_items' => 'integer|min:1|max:100',
        ]);

        try {
            $results = $this->scraper->scrapeNewegg(
                $validated['query'],
                ['max_items' => $validated['max_items'] ?? 20]
            );

            return response()->json([
                'success' => true,
                'source' => 'newegg',
                'query' => $validated['query'],
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
     * Search PC components on eBay
     */
    public function searchEbay(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => 'required|string|max:200',
            'max_items' => 'integer|min:1|max:100',
            'min_price' => 'numeric|min:0',
            'max_price' => 'numeric|min:0',
        ]);

        try {
            $results = $this->scraper->scrapeEbay(
                $validated['query'],
                [
                    'max_items' => $validated['max_items'] ?? 20,
                    'min_price' => $validated['min_price'] ?? 0,
                    'max_price' => $validated['max_price'] ?? 10000,
                ]
            );

            return response()->json([
                'success' => true,
                'source' => 'ebay',
                'query' => $validated['query'],
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
     * Get storage drive pricing (SSDs, HDDs)
     */
    public function getStoragePricing(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'technology' => 'string|in:SSD,HDD',
            'condition' => 'string|in:New,Used,Refurbished',
            'marketplace' => 'string',
            'capacity_gb' => 'integer|min:0',
            'limit' => 'integer|min:1|max:100',
        ]);

        try {
            $results = $this->scraper->getStoragePricing($validated);

            return response()->json([
                'success' => true,
                'source' => 'pricepergig',
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
     * Get cheapest SSDs
     */
    public function getCheapestSSDs(Request $request): JsonResponse
    {
        $limit = $request->input('limit', 10);

        try {
            $results = $this->scraper->getCheapestSSDs($limit);

            return response()->json([
                'success' => true,
                'source' => 'pricepergig',
                'type' => 'SSD',
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
     * Get cheapest HDDs
     */
    public function getCheapestHDDs(Request $request): JsonResponse
    {
        $limit = $request->input('limit', 10);

        try {
            $results = $this->scraper->getCheapestHDDs($limit);

            return response()->json([
                'success' => true,
                'source' => 'pricepergig',
                'type' => 'HDD',
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
}
