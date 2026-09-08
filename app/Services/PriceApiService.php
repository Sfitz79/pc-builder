<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PriceAPI Integration - Real-time e-commerce pricing
 * API: https://www.priceapi.com/
 * Free tier: 1,000 credits
 */
class PriceApiService
{
    protected string $baseUrl = 'https://api.priceapi.com/v2';
    protected string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.priceapi.token', '');
    }

    /**
     * Search Amazon products
     */
    public function searchAmazon(string $query, array $options = []): array
    {
        $country = $options['country'] ?? 'us';
        $limit = $options['limit'] ?? 20;

        return $this->createJob('amazon', [
            'search_term' => $query,
            'country' => $country,
            'max_results' => $limit,
        ]);
    }

    /**
     * Search eBay products
     */
    public function searchEbay(string $query, array $options = []): array
    {
        $country = $options['country'] ?? 'us';
        $limit = $options['limit'] ?? 20;

        return $this->createJob('ebay', [
            'search_term' => $query,
            'country' => $country,
            'max_results' => $limit,
        ]);
    }

    /**
     * Search Google Shopping
     */
    public function searchGoogleShopping(string $query, array $options = []): array
    {
        $country = $options['country'] ?? 'us';
        $limit = $options['limit'] ?? 20;

        return $this->createJob('google_shopping', [
            'search_term' => $query,
            'country' => $country,
            'max_results' => $limit,
        ]);
    }

    /**
     * Get product details from Amazon
     */
    public function getAmazonProduct(string $url): array
    {
        return $this->createJob('amazon_product', [
            'url' => $url,
        ]);
    }

    /**
     * Compare prices across multiple sources
     */
    public function comparePrices(string $query, array $sources = ['amazon', 'ebay', 'google_shopping']): array
    {
        $results = [];
        foreach ($sources as $source) {
            $method = "search" . ucfirst($source === 'google_shopping' ? 'GoogleShopping' : $source);
            if (method_exists($this, $method)) {
                $results[$source] = $this->{$method}($query);
            }
        }
        return $results;
    }

    /**
     * Create a scraping job
     */
    protected function createJob(string $source, array $payload): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => "Token token={$this->apiKey}",
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/jobs", [
                'source' => $source,
                'payload' => $payload,
            ]);

            if ($response->failed()) {
                Log::error('PriceAPI error', [
                    'source' => $source,
                    'status' => $response->status(),
                    'error' => $response->body(),
                ]);
                return [];
            }

            $job = $response->json();
            return $this->waitForJob($job['id'] ?? null);
        } catch (\Exception $e) {
            Log::error('PriceAPI exception', [
                'source' => $source,
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Wait for job completion and return results
     */
    protected function waitForJob(?string $jobId, int $maxAttempts = 30): array
    {
        if (!$jobId) return [];

        for ($i = 0; $i < $maxAttempts; $i++) {
            sleep(2);

            try {
                $response = Http::withHeaders([
                    'Authorization' => "Token token={$this->apiKey}",
                ])->get("{$this->baseUrl}/jobs/{$jobId}");

                if ($response->failed()) continue;

                $job = $response->json();

                if (($job['status'] ?? '') === 'finished') {
                    return $job['result'] ?? [];
                }

                if (($job['status'] ?? '') === 'failed') {
                    Log::error('PriceAPI job failed', ['job_id' => $jobId]);
                    return [];
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return [];
    }
}
