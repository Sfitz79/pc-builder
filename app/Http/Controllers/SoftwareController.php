<?php

namespace App\Http\Controllers;

use App\Models\SoftwareProduct;
use App\Models\SoftwarePurchase;
use App\Services\MetenziException;
use App\Services\MetenziService;
use App\Services\PayPalService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\Factory as ViewFactory;

class SoftwareController extends Controller
{
    public function __construct(
        protected ViewFactory $view,
        protected PayPalService $paypal,
        protected MetenziService $metenzi,
    ) {}

    /**
     * Software store catalogue. The product table is populated lazily on the
     * first visit when Metenzi is configured and nothing has been synced yet.
     */
    public function index(): View
    {
        $this->syncIfEmpty();

        $products = SoftwareProduct::active()
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        return $this->view->make('software.index', [
            'products' => $products,
            'configured' => $this->paypal->configured() && $this->metenzi->configured(),
            'balance' => $this->safeBalance(),
            'gbpRate' => $this->metenzi->gbpRate(),
        ]);
    }

    /**
     * Start a purchase for a software product. Nothing is charged yet — the
     * buyer is sent to the payment page where PayPal handles the payment.
     */
    public function purchase(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:software_products,id'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
        ]);

        $product = SoftwareProduct::findOrFail($data['product_id']);

        if (! $product->active || $product->stock <= 0) {
            return response()->json(['message' => 'This product is out of stock.'], 422);
        }

        $purchase = SoftwarePurchase::create([
            'owner_token' => $this->guestOwnerToken(),
            'product_id' => $product->id,
            'sku' => $product->sku,
            'product_name' => $product->name,
            'amount_gbp' => $product->gbp_price,
            'currency' => $this->paypal->currency(),
            'status' => SoftwarePurchase::STATUS_PENDING,
            'customer_name' => $data['customer_name'] ?? null,
            'customer_email' => $data['customer_email'] ?? null,
        ]);

        return response()->json([
            'purchase_id' => $purchase->uuid,
            'owner_token' => $purchase->owner_token,
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
            ],
            'amount' => (float) $purchase->amount_gbp,
            'currency' => $purchase->currency,
            'paypal_configured' => $this->paypal->configured(),
            'payment_url' => url('/software/purchases/'.$purchase->uuid.'/payment?owner_token='.$purchase->owner_token),
        ], 201);
    }

    public function payment(Request $request, SoftwarePurchase $purchase)
    {
        if (! $this->authorize($purchase, $request)) {
            abort(403);
        }

        return $this->view->make('software.payment', [
            'purchase' => $purchase,
            'paypalConfigured' => $this->paypal->configured(),
            'paypalClientId' => $this->paypal->clientId(),
            'paypalMode' => $this->paypal->isSandbox() ? 'sandbox' : 'live',
        ]);
    }

    /**
     * Create the PayPal order for a purchase and return the approval details.
     */
    public function paypal(Request $request, SoftwarePurchase $purchase): JsonResponse
    {
        if (! $this->authorize($purchase, $request)) {
            return response()->json(['message' => 'Unauthorised.'], 403);
        }

        if (! $this->paypal->configured()) {
            return response()->json(['message' => 'PayPal is not configured yet.'], 503);
        }

        try {
            $paypalOrder = $this->paypal->createOrder(
                (float) $purchase->amount_gbp,
                'PCTG Software — '.$purchase->product_name,
                url('/software/purchases/'.$purchase->uuid.'/paypal/return?owner_token='.urlencode((string) $purchase->owner_token)),
                url('/software')
            );

            $purchase->update([
                'status' => SoftwarePurchase::STATUS_AWAITING_PAYMENT,
                'paypal_order_id' => $paypalOrder['id'],
            ]);

            return response()->json([
                'paypal_order_id' => $paypalOrder['id'],
                'approve_url' => $paypalOrder['approve_url'],
                'client_id' => $this->paypal->clientId(),
                'mode' => $this->paypal->isSandbox() ? 'sandbox' : 'live',
                'amount' => (float) $purchase->amount_gbp,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    /**
     * PayPal approve-link redirect target — return the buyer to the payment
     * page, where the SDK completes the capture.
     */
    public function paypalReturn(Request $request, SoftwarePurchase $purchase)
    {
        return redirect()->route('software.purchases.payment', [
            'purchase' => $purchase->uuid,
            'owner_token' => $request->query('owner_token'),
        ]);
    }

    /**
     * Capture the approved PayPal payment and fulfil the Metenzi order.
     */
    public function capture(Request $request, SoftwarePurchase $purchase): JsonResponse
    {
        $data = $request->validate([
            'paypal_order_id' => ['required', 'string'],
        ]);

        if (! $this->authorize($purchase, $request)) {
            return response()->json(['message' => 'Unauthorised.'], 403);
        }

        if ($purchase->status === SoftwarePurchase::STATUS_FULFILLED) {
            return response()->json($this->confirmationPayload($purchase));
        }

        $capture = $this->paypal->captureOrder($data['paypal_order_id']);

        if ($capture === null || $capture['capture_status'] !== 'COMPLETED') {
            $purchase->update(['status' => SoftwarePurchase::STATUS_FAILED]);

            return response()->json(['message' => 'Payment was not completed.'], 402);
        }

        $purchase->update([
            'status' => SoftwarePurchase::STATUS_PAID,
            'paypal_capture_id' => $capture['capture_id'],
            'paid_at' => now(),
            'customer_name' => $purchase->customer_name ?: ($capture['payer']['name']['given_name'] ?? null),
            'customer_email' => $purchase->customer_email ?: ($capture['payer']['email_address'] ?? null),
        ]);

        $this->fulfil($purchase->fresh());

        return response()->json($this->confirmationPayload($purchase->fresh()));
    }

    /**
     * Re-attempt fulfilment for a paid purchase whose keys have not arrived
     * (e.g. the Metenzi balance was empty at the time of payment). Safe to
     * call repeatedly — order creation is idempotent via the idempotency key.
     */
    public function retryFulfilment(Request $request, SoftwarePurchase $purchase): JsonResponse
    {
        if (! $this->authorize($purchase, $request)) {
            return response()->json(['message' => 'Unauthorised.'], 403);
        }

        if ($purchase->status !== SoftwarePurchase::STATUS_PAID || $purchase->keys) {
            return response()->json(['message' => 'Nothing to fulfil.'], 422);
        }

        $this->fulfil($purchase->fresh());

        return response()->json($this->confirmationPayload($purchase->fresh()));
    }

    /**
     * Post-payment confirmation page showing delivered keys.
     */
    public function confirmed(Request $request, SoftwarePurchase $purchase): View
    {
        if (! $this->authorize($purchase, $request)) {
            abort(403);
        }

        return $this->view->make('software.confirmation', [
            'purchase' => $purchase,
        ]);
    }

    /**
     * Metenzi webhook receiver (CSRF-exempt, HMAC-verified).
     */
    public function webhook(Request $request): JsonResponse
    {
        if (! $this->metenzi->verifyWebhook($request)) {
            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        $event = $request->json()->all();
        $type = (string) ($event['event'] ?? '');
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];

        $this->handleWebhookEvent($type, $data);

        return response()->json(['success' => true]);
    }

    protected function fulfil(SoftwarePurchase $purchase): void
    {
        if (! $this->metenzi->configured()) {
            $purchase->update(['notes' => 'Metenzi is not configured — keys cannot be delivered.']);

            return;
        }

        try {
            $result = $this->metenzi->createOrder(
                items: [[
                    'productId' => $purchase->product?->metenzi_product_id ?? $purchase->sku,
                    'quantity' => 1,
                    'maxUnitPrice' => (string) ($purchase->product?->retail_price ?? 0),
                ]],
                externalOrderId: 'SW-'.$purchase->uuid,
                idempotencyKey: 'sw-'.$purchase->uuid,
            );

            $purchase->update([
                'metenzi_order_id' => $result['id'] ?? null,
                'metenzi_status' => $result['status'] ?? null,
            ]);

            if (($result['status'] ?? '') === 'paid' && isset($result['id'])) {
                $detail = $this->metenzi->order($result['id']);
                $keys = $this->keysFromDetail($detail);

                if ($keys !== []) {
                    $purchase->update([
                        'keys' => $keys,
                        'status' => SoftwarePurchase::STATUS_FULFILLED,
                        'fulfilled_at' => now(),
                        'notes' => null,
                    ]);
                }
            }
        } catch (MetenziException $e) {
            report($e);
            $purchase->update(['notes' => 'Fulfilment pending — '.$e->getMessage()]);
        }
    }

    protected function handleWebhookEvent(string $type, array $data): void
    {
        $metenziOrderId = (string) ($data['orderId'] ?? '');
        $purchase = null;

        if ($metenziOrderId !== '') {
            $purchase = SoftwarePurchase::where('metenzi_order_id', $metenziOrderId)->first();
        }

        if ($purchase === null) {
            $external = (string) ($data['externalOrderId'] ?? '');

            if (str_starts_with($external, 'SW-')) {
                $purchase = SoftwarePurchase::where('uuid', substr($external, 3))->first();
            }
        }

        if ($purchase === null) {
            return;
        }

        $purchase->update(['last_webhook_event' => $type]);

        if ($type === 'order.fulfilled') {
            $keys = collect($data['items'] ?? [])
                ->flatMap(fn ($item) => is_array($item['keys'] ?? null) ? $item['keys'] : [])
                ->map(fn ($key) => [
                    'code' => (string) ($key['code'] ?? ''),
                    'type' => (string) ($key['codeType'] ?? 'text/plain'),
                ])
                ->values()
                ->all();

            if ($keys !== []) {
                $purchase->update([
                    'keys' => $keys,
                    'status' => SoftwarePurchase::STATUS_FULFILLED,
                    'fulfilled_at' => now(),
                    'notes' => null,
                ]);
            }
        } elseif ($type === 'order.backorder') {
            $purchase->update(['status' => SoftwarePurchase::STATUS_BACKORDER]);
        } elseif ($type === 'order.cancelled') {
            $purchase->update(['status' => SoftwarePurchase::STATUS_CANCELLED]);
        }
    }

    protected function keysFromDetail(array $detail): array
    {
        return collect($detail['keys'] ?? [])
            ->map(fn ($key) => [
                'code' => (string) ($key['code'] ?? ''),
                'type' => (string) ($key['codeType'] ?? 'text/plain'),
                'productId' => (string) ($key['productId'] ?? ''),
            ])
            ->values()
            ->all();
    }

    protected function syncIfEmpty(): void
    {
        if (! $this->metenzi->configured() || SoftwareProduct::query()->exists()) {
            return;
        }

        try {
            Cache::lock('software.sync', 120)->get(function (): void {
                if (SoftwareProduct::query()->exists()) {
                    return;
                }

                Artisan::call('software:sync');
            });
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function safeBalance(): ?array
    {
        if (! $this->metenzi->configured()) {
            return null;
        }

        try {
            return $this->metenzi->balance();
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    protected function purchasePayload(SoftwarePurchase $purchase): array
    {
        return [
            'purchase_id' => $purchase->uuid,
            'status' => $purchase->status,
            'currency' => $purchase->currency,
            'product_name' => $purchase->product_name,
            'sku' => $purchase->sku,
            'amount' => (float) $purchase->amount_gbp,
            'paypal_configured' => $this->paypal->configured(),
            'paypal_client_id' => $this->paypal->clientId(),
            'paypal_mode' => $this->paypal->isSandbox() ? 'sandbox' : 'live',
        ];
    }

    protected function confirmationPayload(SoftwarePurchase $purchase): array
    {
        $payload = $this->purchasePayload($purchase);
        $payload['confirmed'] = true;
        $payload['paypal_capture_id'] = $purchase->paypal_capture_id;
        $payload['paid_at'] = $purchase->paid_at?->toIso8601String();
        $payload['confirmation_url'] = url('/software/purchases/'.$purchase->uuid.'/confirmed?owner_token='.urlencode((string) $purchase->owner_token));

        if ($purchase->status === SoftwarePurchase::STATUS_FULFILLED) {
            $payload['keys'] = $purchase->keys ?? [];
        }

        $payload['notes'] = $purchase->notes;

        return $payload;
    }

    protected function authorize(SoftwarePurchase $purchase, Request $request): bool
    {
        $token = $request->header('X-Owner-Token') ?: $request->query('owner_token');

        return is_string($token) && $purchase->isOwnedByToken($token);
    }

    protected function guestOwnerToken(): string
    {
        return (string) (session()->get('guest_owner_token') ?? session()->getId());
    }
}
