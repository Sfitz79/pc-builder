<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * BuyWhere API Integration - Product catalog and price comparison
 * API: https://api.buywhere.ai/v1
 * Docs: https://api.buywhere.ai/
 */
class BuyWhereService
{
    protected string $baseUrl = 'https://api.buywhere.ai/v1';
    protected string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.buywhere.api_key', '');
    }

    /**
     * Search products across retailers
     */
    public function searchProducts(string $query, array $options = []): array
    {
        $limit = $options['limit'] ?? 20;
        $category = $options['category'] ?? null;

        $params = [
            'q' => $query,
            'limit' => $limit,
        ];

        if ($category) {
            $params['category'] = $category;
        }

        return $this->makeRequest('/search', $params);
    }

    /**
     * Get product details by ID
     */
    public function getProduct(string $productId): array
    {
        return $this->makeRequest("/products/{$productId}");
    }

    /**
     * Compare prices across retailers
     */
    public function comparePrices(string $productId): array
    {
        return $this->makeRequest("/products/{$productId}/prices");
    }

    /**
     * Get deals and discounts
     */
    public function getDeals(array $options = []): array
    {
        $limit = $options['limit'] ?? 20;
        $category = $options['category'] ?? null;

        $params = ['limit' => $limit];
        if ($category) {
            $params['category'] = $category;
        }

        return $this->makeRequest('/deals', $params);
    }

    /**
     * Get product categories
     */
    public function getCategories(): array
    {
        return $this->makeRequest('/categories');
    }

    /**
     * Get price history for a product
     */
    public function getPriceHistory(string $productId): array
    {
        return $this->makeRequest("/products/{$productId}/price-history");
    }

    /**
     * Search specifically for PC components
     */
    public function searchPcComponents(string $query, array $options = []): array
    {
        $pcCategories = [
            'processors',
            'graphics-cards',
            'motherboards',
            'memory',
            'storage',
            'power-supplies',
            'cases',
            'cooling',
        ];

        $results = [];
        foreach ($pcCategories as $category) {
            $items = $this->searchProducts($query, [
                'category' => $category,
                'limit' => $options['limit'] ?? 10,
            ]);
            $results = array_merge($results, $items);
        }

        return $results;
    }

    /**
     * Make API request
     */
    protected function makeRequest(string $endpoint, array $params = []): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Accept' => 'application/json',
            ])->get("{$this->baseUrl}{$endpoint}", $params);

            if ($response->failed()) {
                Log::error('BuyWhere API error', [
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                    'error' => $response->body(),
                ]);
                return [];
            }

            return $response->json('data', $response->json());
        } catch (\Exception $e) {
            Log::error('BuyWhere API exception', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
}
