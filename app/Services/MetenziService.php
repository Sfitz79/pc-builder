<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client for the Metenzi REST API (products, orders, balance, webhooks).
 *
 * Secrets are read from config (environment only) — never hardcoded. Outgoing
 * write requests are HMAC-SHA256 signed when a signing secret is configured
 * (canonical string: timestamp.method.path.body, dot-separated). Incoming
 * webhook deliveries are verified against the registered webhook secret.
 */
class MetenziService
{
    public function configured(): bool
    {
        return filled(config('metenzi.api_key'));
    }

    public function baseUrl(): string
    {
        return rtrim((string) config('metenzi.base_url'), '/');
    }

    public function currency(): string
    {
        return (string) config('metenzi.currency', 'GBP');
    }

    public function gbpRate(): float
    {
        return (float) config('metenzi.gbp_rate', 0.85);
    }

    /**
     * @return array<string, mixed> Decoded JSON body of a successful response.
     *
     * @throws MetenziException
     */
    public function request(string $method, string $path, array $body = [], array $query = []): array
    {
        if (! $this->configured()) {
            throw new MetenziException('Metenzi is not configured (METENZI_API_KEY missing).');
        }

        $headers = ['Authorization' => 'Bearer ' . config('metenzi.api_key')];

        if (in_array(strtoupper($method), ['POST', 'PATCH', 'DELETE'], true)) {
            $signingSecret = config('metenzi.signing_secret');

            if (filled($signingSecret)) {
                $timestamp = (string) (int) (microtime(true) * 1000);
                $rawBody = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
                $signature = hash_hmac('sha256', implode('.', [$timestamp, strtoupper($method), $path, $rawBody]), (string) $signingSecret);

                $headers['X-Signature'] = $signature;
                $headers['X-Signature-Timestamp'] = $timestamp;
            }
        }

        $response = Http::withHeaders($headers)
            ->acceptJson()
            ->timeout(30)
            ->send(strtoupper($method), $this->baseUrl() . $path, $body !== [] ? ['json' => $body] : ['query' => $query]);

        if (! $response->successful()) {
            throw new MetenziException($this->errorMessage($response));
        }

        return $response->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function balance(): array
    {
        return $this->request('GET', '/balance')['data'] ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function products(bool $retrieveAll = true, int $limit = 200): array
    {
        $data = $this->request('GET', '/products', [], $retrieveAll ? ['retrieveAll' => 'true'] : ['limit' => $limit]);

        return $data['data'] ?? [];
    }

    /**
     * Place an order for one or more license-key products.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     *
     * @throws MetenziException
     */
    public function createOrder(array $items, string $externalOrderId, ?string $idempotencyKey = null): array
    {
        $data = $this->request('POST', '/orders', [
            'items' => $items,
            'externalOrderId' => $externalOrderId,
            ...($idempotencyKey !== null ? ['idempotencyKey' => $idempotencyKey] : []),
        ]);

        return $data['data'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function order(string $metenziOrderId): array
    {
        $data = $this->request('GET', '/orders/' . $metenziOrderId);

        return $data['data'] ?? [];
    }

    /**
     * Re-trigger fulfilment for a paid Metenzi order that is missing keys.
     *
     * @return array<string, mixed>
     */
    public function confirmPayment(string $metenziOrderId): array
    {
        $data = $this->request('POST', '/orders/' . $metenziOrderId . '/confirm-payment');

        return $data['data'] ?? [];
    }

    /**
     * Verify the HMAC signature on an incoming Metenzi webhook delivery.
     *
     * Supports both documented schemes:
     *  - X-Webhook-Signature: HMAC-SHA256 of the raw request body.
     *  - X-Metenzi-Signature (canonical): HMAC-SHA256 of timestamp.event.body.
     *
     * When no webhook secret is configured the delivery is accepted but
     * logged, so the store keeps working while credentials are being set up.
     */
    public function verifyWebhook(Request $request): bool
    {
        $secret = config('metenzi.webhook_secret');

        if (! filled($secret)) {
            Log::warning('Metenzi webhook received without a configured webhook secret.');

            return true;
        }

        $rawBody = $request->getContent();

        $signature = (string) $request->header('X-Webhook-Signature');

        if ($signature === '') {
            $timestamp = (string) $request->header('X-Metenzi-Timestamp', '');
            $eventType = (string) $request->header('X-Metenzi-Event', '');
            $signature = (string) $request->header('X-Metenzi-Signature');
            $expected = hash_hmac('sha256', implode('.', [$timestamp, $eventType, $rawBody]), (string) $secret);
        } else {
            $expected = hash_hmac('sha256', $rawBody, (string) $secret);
        }

        return hash_equals($expected, $signature);
    }

    protected function errorMessage(Response $response): string
    {
        $json = $response->json();

        if (is_array($json)) {
            $message = $json['error']['message'] ?? $json['message'] ?? $response->body();

            return trim((string) $message);
        }

        return trim((string) $response->body());
    }
}
