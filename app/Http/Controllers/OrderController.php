<?php

namespace App\Http\Controllers;

use App\Models\Build;
use App\Models\Order;
use App\Services\PayPalService;
use App\Services\SystemMockupService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\Factory as ViewFactory;

class OrderController extends Controller
{
    protected array $validCategories = [
        'cpu',
        'motherboard',
        'gpu',
        'ram',
        'storage',
        'psu',
        'case',
    ];

    public function __construct(
        protected ViewFactory $view,
        protected PayPalService $paypal,
        protected SystemMockupService $mockup,
    ) {
    }

    /**
     * Create an order (and its backing build) from the current selection.
     * Prices are re-computed from the database so the order is authoritative.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'resolution' => ['nullable', Rule::in(['1080P', '1440P', '4K'])],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'components' => ['required', 'array', 'min:1'],
            'components.*.category' => ['required', Rule::in($this->validCategories)],
            'components.*.id' => ['required', 'integer', 'exists:components,id'],
        ]);

        $selected = collect($data['components'])->keyBy('category');
        $components = \App\Models\Component::query()
            ->whereIn('id', $selected->pluck('id'))
            ->with('category')
            ->get()
            ->keyBy('id');

        if ($components->count() !== $selected->count()) {
            return response()->json(['message' => 'One or more components are no longer available.'], 422);
        }

        $partsTotal = (float) $selected->sum(fn ($item) => (float) $components[$item['id']]->price);
        $buildDelivery = (float) config('pricing.build_delivery');
        $subtotal = round($partsTotal + $buildDelivery, 2);
        $paypalFee = round($subtotal * (float) config('pricing.paypal_fee_rate'), 2);
        $total = round($subtotal + $paypalFee, 2);

        $build = Build::create([
            'user_id' => auth()->id(),
            'owner_token' => auth()->check() ? null : $this->guestOwnerToken(),
            'name' => $data['name'] ?? 'Custom Build',
            'purpose' => $data['purpose'] ?? null,
            'resolution' => $data['resolution'] ?? '1440P',
            'budget' => $data['budget'] ?? null,
            'total_price' => $partsTotal,
            'performance_score' => 0,
            'compatibility_checks' => [],
            'share_slug' => \Illuminate\Support\Str::random(10),
        ]);

        foreach ($selected as $category => $item) {
            $build->components()->attach($item['id'], [
                'category' => $category,
                'price_snapshot' => $components[$item['id']]->price,
            ]);
        }

        $ownerToken = $build->owner_token ?? $this->guestOwnerToken();

        $order = Order::create([
            'user_id' => auth()->id(),
            'owner_token' => $ownerToken,
            'build_id' => $build->id,
            'status' => Order::STATUS_DRAFT,
            'currency' => config('pricing.currency', 'GBP'),
            'customer_name' => $data['customer_name'] ?? null,
            'customer_email' => $data['customer_email'] ?? null,
            'parts_total' => $partsTotal,
            'build_delivery' => $buildDelivery,
            'subtotal' => $subtotal,
            'paypal_fee' => $paypalFee,
            'total' => $total,
            'payload' => [
                'line_items' => $selected->map(fn ($item, $category) => [
                    'category' => $category,
                    'id' => $item['id'],
                    'name' => $components[$item['id']]->name,
                    'detail' => (string) $components[$item['id']]->tags,
                    'price' => (float) $components[$item['id']]->price,
                ])->values()->all(),
            ],
        ]);

        return response()->json([
            'order_id' => $order->uuid,
            'owner_token' => $ownerToken,
            'build_id' => $build->uuid,
            'share_slug' => $build->share_slug,
            'build_name' => $build->name,
            'resolution' => $build->resolution,
            'line_items' => $order->payload['line_items'] ?? [],
            'amounts' => [
                'parts_total' => $partsTotal,
                'build_delivery' => $buildDelivery,
                'subtotal' => $subtotal,
                'paypal_fee' => $paypalFee,
                'total' => $total,
                'currency' => $order->currency,
            ],
            'mockup_url' => $this->mockupUrl($build),
        ], 201);
    }

    /**
     * JSON summary used to hydrate the payment page.
     */
    public function show(Request $request, string $orderUuid): JsonResponse
    {
        $order = Order::with('build.selectedComponents')
            ->where('uuid', $orderUuid)
            ->firstOrFail();

        if (! $this->authorize($order, $request)) {
            return response()->json(['message' => 'Unauthorised.'], 403);
        }

        return response()->json($this->orderPayload($order));
    }

    /**
     * Create the PayPal order for a draft order and return the approval link.
     */
    public function paypal(Request $request, string $orderUuid): JsonResponse
    {
        $order = Order::where('uuid', $orderUuid)->firstOrFail();

        if (! $this->authorize($order, $request)) {
            return response()->json(['message' => 'Unauthorised.'], 403);
        }

        if (! $this->paypal->configured()) {
            return response()->json(['message' => 'PayPal is not configured yet.'], 503);
        }

        try {
            $paypalOrder = $this->paypal->createOrder(
                (float) $order->total,
                'PCTG Custom PC — ' . ($order->build?->name ?? 'Custom Build'),
                url('/builder/orders/' . $order->uuid . '/paypal/return'),
                url('/builder/checkout/payment?order=' . $order->uuid)
            );

            $order->update([
                'status' => Order::STATUS_AWAITING_PAYMENT,
                'paypal_order_id' => $paypalOrder['id'],
            ]);

            return response()->json([
                'paypal_order_id' => $paypalOrder['id'],
                'approve_url' => $paypalOrder['approve_url'],
                'client_id' => $this->paypal->clientId(),
                'mode' => $this->paypal->isSandbox() ? 'sandbox' : 'live',
                'amount' => (float) $order->total,
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    /**
     * PayPal approve-link redirect target — return the client to payment to
     * complete the capture.
     */
    public function paypalReturn(Request $request, string $orderUuid)
    {
        return redirect()->route('builder.checkout.payment', ['order' => $orderUuid]);
    }

    /**
     * Capture an approved PayPal order, finalise the order and generate the
     * confirmed invoice.
     */
    public function capture(Request $request, string $orderUuid): JsonResponse
    {
        $data = $request->validate([
            'paypal_order_id' => ['required', 'string'],
        ]);

        $order = Order::where('uuid', $orderUuid)->firstOrFail();

        if (! $this->authorize($order, $request)) {
            return response()->json(['message' => 'Unauthorised.'], 403);
        }

        if ($order->status === Order::STATUS_PAID) {
            return response()->json($this->confirmationPayload($order));
        }

        $capture = $this->paypal->captureOrder($data['paypal_order_id']);

        if ($capture === null || $capture['capture_status'] !== 'COMPLETED') {
            $order->update(['status' => Order::STATUS_FAILED]);

            return response()->json(['message' => 'Payment was not completed.'], 402);
        }

        $order->update([
            'status' => Order::STATUS_PAID,
            'paypal_capture_id' => $capture['capture_id'],
            'paypal_invoice_id' => $this->paypal->createInvoice($order),
            'paid_at' => now(),
            'customer_name' => $order->customer_name ?: ($capture['payer']['name']['given_name'] ?? null),
            'customer_email' => $order->customer_email ?: ($capture['payer']['email_address'] ?? null),
        ]);

        return response()->json($this->confirmationPayload($order->fresh()));
    }

    /**
     * Post-payment confirmation / invoice screen.
     */
    public function confirmed(Request $request, string $orderUuid): View
    {
        $order = Order::with('build.selectedComponents')
            ->where('uuid', $orderUuid)
            ->firstOrFail();

        return $this->view->make('builder.confirmation', [
            'order' => $order,
            'lineItems' => $order->payload['line_items'] ?? [],
        ]);
    }

    /**
     * Rendered mockup image of the complete system.
     */
    public function mockup(string $buildUuid)
    {
        $build = Build::with('selectedComponents')->where('uuid', $buildUuid)->firstOrFail();

        return response($this->mockup->render($build), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    protected function orderPayload(Order $order): array
    {
        return [
            'order_id' => $order->uuid,
            'status' => $order->status,
            'currency' => $order->currency,
            'build' => [
                'name' => $order->build?->name,
                'resolution' => $order->build?->resolution,
                'share_slug' => $order->build?->share_slug,
                'mockup_url' => $this->mockupUrl($order->build),
            ],
            'line_items' => $order->payload['line_items'] ?? [],
            'amounts' => [
                'parts_total' => (float) $order->parts_total,
                'build_delivery' => (float) $order->build_delivery,
                'subtotal' => (float) $order->subtotal,
                'paypal_fee' => (float) $order->paypal_fee,
                'total' => (float) $order->total,
            ],
            'paypal_configured' => $this->paypal->configured(),
            'paypal_client_id' => $this->paypal->clientId(),
            'paypal_mode' => $this->paypal->isSandbox() ? 'sandbox' : 'live',
        ];
    }

    protected function confirmationPayload(Order $order): array
    {
        $payload = $this->orderPayload($order);
        $payload['confirmed'] = true;
        $payload['paypal_capture_id'] = $order->paypal_capture_id;
        $payload['paypal_invoice_id'] = $order->paypal_invoice_id;
        $payload['paid_at'] = $order->paid_at?->toIso8601String();
        $payload['confirmation_url'] = url('/builder/orders/' . $order->uuid . '/confirmed');

        return $payload;
    }

    protected function mockupUrl(?Build $build): ?string
    {
        return $build === null ? null : url('/builder/builds/' . $build->uuid . '/mockup.png');
    }

    protected function authorize(Order $order, Request $request): bool
    {
        if ($order->user_id !== null && auth()->check() && $order->user_id === auth()->id()) {
            return true;
        }

        if ($order->owner_token !== null) {
            $token = $request->header('X-Owner-Token') ?: $request->query('owner_token');

            if (is_string($token) && $order->isOwnedByToken($token)) {
                return true;
            }
        }

        return false;
    }

    protected function guestOwnerToken(): string
    {
        return (string) (session()->get('guest_owner_token') ?? session()->getId());
    }
}
