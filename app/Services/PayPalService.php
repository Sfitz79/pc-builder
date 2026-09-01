<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Http;

class PayPalService
{
    public function configured(): bool
    {
        return filled(config('services.paypal.client_id'))
            && filled(config('services.paypal.secret'));
    }

    public function isSandbox(): bool
    {
        return config('services.paypal.mode', 'sandbox') === 'sandbox';
    }

    public function baseUrl(): string
    {
        return $this->isSandbox()
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
    }

    public function clientId(): string
    {
        return (string) config('services.paypal.client_id');
    }

    public function currency(): string
    {
        return (string) config('services.paypal.currency', 'GBP');
    }

    /**
     * Request an OAuth2 access token from PayPal.
     *
     * @throws \RuntimeException
     */
    public function accessToken(): string
    {
        $response = Http::asForm()
            ->withBasicAuth($this->clientId(), config('services.paypal.secret'))
            ->post($this->baseUrl() . '/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('PayPal authentication failed: ' . $response->body());
        }

        return (string) $response->json('access_token');
    }

    /**
     * Create a PayPal order for the given amount (in the store currency).
     *
     * @return array{id: string, approve_url: string, status: string}
     *
     * @throws \RuntimeException
     */
    public function createOrder(float $amount, string $description, string $returnUrl, string $cancelUrl): array
    {
        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->post($this->baseUrl() . '/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => $description,
                    'description' => $description,
                    'amount' => [
                        'currency_code' => $this->currency(),
                        'value' => number_format($amount, 2, '.', ''),
                    ],
                ]],
                'application_context' => [
                    'return_url' => $returnUrl,
                    'cancel_url' => $cancelUrl,
                    'user_action' => 'PAY_NOW',
                    'brand_name' => config('app.name', 'PCTG Builder'),
                    'shipping_preference' => 'NO_SHIPPING',
                ],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('PayPal order creation failed: ' . $response->body());
        }

        $approveUrl = collect($response->json('links') ?? [])
            ->firstWhere('rel', 'approve')['href'] ?? null;

        return [
            'id' => (string) $response->json('id'),
            'approve_url' => (string) ($approveUrl ?? $response->json('id')),
            'status' => (string) $response->json('status'),
        ];
    }

    /**
     * Capture an approved PayPal order.
     *
     * @return array<string, mixed>|null Payload on success, null on failure.
     */
    public function captureOrder(string $paypalOrderId): ?array
    {
        $response = Http::withToken($this->accessToken())
            ->acceptJson()
            ->post($this->baseUrl() . '/v2/checkout/orders/' . $paypalOrderId . '/capture');

        if (! $response->successful()) {
            return null;
        }

        $capture = collect($response->json('purchase_units.0.payments.captures') ?? [])->first();

        return [
            'status' => (string) $response->json('status'),
            'capture_id' => (string) ($capture['id'] ?? ''),
            'capture_status' => (string) ($capture['status'] ?? ''),
            'payer' => $response->json('payer') ?? [],
            'amount' => $capture['amount'] ?? null,
        ];
    }

    /**
     * Generate a PayPal invoice itemising the confirmed order prices. Best
     * effort: the merchant account must have invoicing enabled, otherwise a
     * failure is swallowed and the local invoice remains the record of record.
     */
    public function createInvoice(Order $order): ?string
    {
        $lines = [];

        foreach (($order->payload['line_items'] ?? []) as $item) {
            $lines[] = [
                'name' => (string) $item['name'],
                'description' => $item['detail'] ?? '',
                'unit_amount' => [
                    'currency_code' => $order->currency,
                    'value' => number_format((float) $item['price'], 2, '.', ''),
                ],
                'quantity' => '1',
            ];
        }

        if ($order->build_delivery > 0) {
            $lines[] = [
                'name' => 'Build & Delivery',
                'description' => 'Fixed build and delivery fee',
                'unit_amount' => [
                    'currency_code' => $order->currency,
                    'value' => number_format((float) $order->build_delivery, 2, '.', ''),
                ],
                'quantity' => '1',
            ];
        }

        if ($order->paypal_fee > 0) {
            $lines[] = [
                'name' => 'PayPal Processing Fee',
                'description' => '3% payment processing fee',
                'unit_amount' => [
                    'currency_code' => $order->currency,
                    'value' => number_format((float) $order->paypal_fee, 2, '.', ''),
                ],
                'quantity' => '1',
            ];
        }

        $payload = [
            'detail' => [
                'invoice_number' => 'PCTG-' . strtoupper(substr((string) $order->uuid, 0, 8)),
                'currency_code' => $order->currency,
                'invoice_date' => now()->toDateString(),
                'payment_term' => ['term_type' => 'DUE_ON_RECEIPT'],
            ],
            'primary_recipients' => [[
                'billing_info' => [
                    'email_address' => $order->customer_email ?: 'customer@example.com',
                    'name' => ['given_name' => $order->customer_name ?: 'PCTG Customer'],
                ],
            ]],
            'items' => $lines,
            'amount' => [
                'currency_code' => $order->currency,
                'value' => number_format((float) $order->total, 2, '.', ''),
                'breakdown' => [
                    'custom' => [
                        'label' => 'Total due',
                        'amount' => [
                            'currency_code' => $order->currency,
                            'value' => number_format((float) $order->total, 2, '.', ''),
                        ],
                    ],
                ],
            ],
        ];

        try {
            $response = Http::withToken($this->accessToken())
                ->acceptJson()
                ->post($this->baseUrl() . '/v2/invoicing/invoices', $payload);

            if (! $response->successful()) {
                return null;
            }

            return (string) $response->json('id');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
