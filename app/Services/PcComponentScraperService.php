<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PcComponentScraperService
{
    protected string $apifyToken;
    protected string $apifyBaseUrl = 'https://api.apify.com/v2';

    public function __construct()
    {
        $this->apifyToken = config('services.apify.token', '');
    }

    /**
     * Scrape Amazon products for PC components
     */
    public function scrapeAmazon(string $searchQuery, array $options = []): array
    {
        $maxItems = $options['max_items'] ?? 20;
        $minPrice = $options['min_price'] ?? 0;
        $maxPrice = $options['max_price'] ?? 10000;

        $run = $this->apifyPost('/acts/junglee~amazon-crawler/runs', [
            'searchTerms' => [$searchQuery],
            'maxItems' => $maxItems,
            'minPrice' => $minPrice,
            'maxPrice' => $maxPrice,
            'country' => 'US',
        ]);

        return $this->getDatasetItems($run['defaultDatasetId']);
    }

    /**
     * Scrape Newegg products
     */
    public function scrapeNewegg(string $searchQuery, array $options = []): array
    {
        $maxItems = $options['max_items'] ?? 20;

        $run = $this->apifyPost('/acts/epctex~newegg-scraper/runs', [
            'searchUrl' => "https://www.newegg.com/p/pl?d=" . urlencode($searchQuery),
            'maxItems' => $maxItems,
        ]);

        return $this->getDatasetItems($run['defaultDatasetId']);
    }

    /**
     * Scrape eBay products
     */
    public function scrapeEbay(string $searchQuery, array $options = []): array
    {
        $maxItems = $options['max_items'] ?? 20;
        $minPrice = $options['min_price'] ?? 0;
        $maxPrice = $options['max_price'] ?? 10000;

        $run = $this->apifyPost('/acts/epctex~ebay-scraper/runs', [
            'searchTerms' => [$searchQuery],
            'maxItems' => $maxItems,
            'minPrice' => $minPrice,
            'maxPrice' => $maxPrice,
            'country' => 'US',
        ]);

        return $this->getDatasetItems($run['defaultDatasetId']);
    }

    /**
     * Get storage drive pricing from PricePerGig.com (Free API)
     */
    public function getStoragePricing(array $options = []): array
    {
        $technology = $options['technology'] ?? 'SSD';
        $condition = $options['condition'] ?? 'New';
        $marketplace = $options['marketplace'] ?? 'amazon.com';
        $capacityGb = $options['capacity_gb'] ?? null;
        $limit = $options['limit'] ?? 20;

        $url = "https://api.pricepergig.com/drives?"
            . "technology=eq.{$technology}"
            . "&condition=eq.{$condition}"
            . "&marketplace=eq.{$marketplace}"
            . "&order=price_per_tb.asc"
            . "&limit={$limit}";

        if ($capacityGb) {
            $url .= "&capacity_gb=gte.{$capacityGb}";
        }

        $response = Http::get($url);

        if ($response->failed()) {
            Log::error('PricePerGig API error', [
                'status' => $response->status(),
                'url' => $url,
            ]);
            return [];
        }

        return $response->json();
    }

    /**
     * Get cheapest SSDs
     */
    public function getCheapestSSDs(int $limit = 10): array
    {
        return $this->getStoragePricing([
            'technology' => 'SSD',
            'condition' => 'New',
            'marketplace' => 'amazon.com',
            'limit' => $limit,
        ]);
    }

    /**
     * Get cheapest HDDs
     */
    public function getCheapestHDDs(int $limit = 10): array
    {
        return $this->getStoragePricing([
            'technology' => 'HDD',
            'condition' => 'New',
            'marketplace' => 'amazon.com',
            'limit' => $limit,
        ]);
    }

    /**
     * Search storage by capacity
     */
    public function searchStorageByCapacity(int $capacityGb, string $technology = 'SSD'): array
    {
        return $this->getStoragePricing([
            'technology' => $technology,
            'condition' => 'New',
            'marketplace' => 'amazon.com',
            'capacity_gb' => $capacityGb,
            'limit' => 20,
        ]);
    }

    /**
     * Make POST request to Apify API
     */
    protected function apifyPost(string $endpoint, array $data): array
    {
        $url = "{$this->apifyBaseUrl}{$endpoint}?token={$this->apifyToken}";

        $response = Http::post($url, $data);

        if ($response->failed()) {
            Log::error('Apify API error', [
                'status' => $response->status(),
                'url' => $url,
                'error' => $response->body(),
            ]);
            throw new \Exception('Failed to run Apify actor: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Get dataset items from Apify
     */
    protected function getDatasetItems(string $datasetId): array
    {
        $url = "{$this->apifyBaseUrl}/datasets/{$datasetId}/items?token={$this->apifyToken}";

        $response = Http::get($url);

        if ($response->failed()) {
            Log::error('Apify dataset error', [
                'status' => $response->status(),
                'dataset_id' => $datasetId,
            ]);
            return [];
        }

        return $response->json();
    }
}
