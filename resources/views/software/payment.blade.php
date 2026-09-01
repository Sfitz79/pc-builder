@extends('layouts.seo')

@section('title', 'Checkout — ' . $purchase->product_name . ' | PCTG Builder')

@section('description', 'Complete your software purchase securely with PayPal.')

@section('content')
    <div class="mx-auto max-w-3xl" x-data="softwarePayment({{ json_encode([
        'client_id' => $paypalClientId,
        'mode' => $paypalMode,
        'purchase_id' => $purchase->uuid,
        'currency' => $purchase->currency,
    ]) }})" x-init="init">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-pctg-primary-hover">Checkout</p>
                <h1 class="mt-2 font-display text-2xl font-bold text-white">Complete your purchase</h1>
            </div>
            <x-pctg.badge variant="success" dot x-show="status === 'paid'">Paid</x-pctg.badge>
        </div>

        <div class="mt-8 rounded-2xl border border-white/5 bg-pctg-surface p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-medium uppercase tracking-[0.14em] text-pctg-text-secondary">Software</p>
                    <h2 class="mt-1 font-display text-lg font-bold text-white">{{ $purchase->product_name }}</h2>
                    <p class="mt-1 text-xs text-pctg-text-secondary">{{ $purchase->sku }}</p>
                </div>
                <p class="font-display text-3xl font-bold text-white">
                    &pound;{{ number_format((float) $purchase->amount_gbp, 2) }}
                </p>
            </div>
        </div>

        <div class="mt-6 rounded-2xl border border-white/5 bg-pctg-surface p-6">
            <h3 class="flex items-center gap-2 font-display text-lg font-bold text-white">
                <x-pctg.icon name="wallet" class="h-5 w-5 text-pctg-primary-hover" />
                Pay with PayPal
            </h3>
            <p class="mt-2 text-sm text-pctg-text-secondary">
                You will be taken to PayPal and returned here automatically. Your key is shown on the next page the
                moment payment clears. No card details are stored on our servers.
            </p>

            <div id="paypal-button-container" class="mt-5 min-h-[3rem]"></div>

            @if (! $paypalConfigured)
                <div class="mt-5 rounded-xl border border-amber-500/20 bg-amber-500/5 p-4 text-sm text-amber-300">
                    Online payment is not enabled yet — your purchase is saved, but you can't complete it until PayPal
                    is configured.
                </div>
            @endif

            <div
                x-show="error"
                x-cloak
                class="mt-5 rounded-xl border border-red-500/20 bg-red-500/5 p-4 text-sm text-red-300"
                x-text="error"
            ></div>
        </div>

        <p class="mt-6 text-center text-xs text-pctg-text-secondary">
            By paying you agree to our
            <a href="{{ route('terms') }}" class="underline transition hover:text-white">Terms of Service</a>
            &middot; Software is delivered digitally.
        </p>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('softwarePayment', (options) => ({
                status: 'pending',
                error: null,
                sdkPromise: null,
                buttonsRendered: false,

                async init() {
                    if (!options.client_id) return;

                    this.status = 'ready';

                    try {
                        const paypal = await this.loadSdk();
                        this.renderButtons(paypal);
                    } catch (e) {
                        this.error = 'PayPal could not be loaded. Please try again.';
                    }
                },

                loadSdk() {
                    if (this.sdkPromise) return this.sdkPromise;

                    const base = options.mode === 'sandbox' ? 'https://www.sandbox.paypal.com/sdk/js' : 'https://www.paypal.com/sdk/js';
                    const url = base + '?client-id=' + encodeURIComponent(options.client_id) +
                        '&intent=capture&currency=' + encodeURIComponent(options.currency) + '&commit=true';

                    this.sdkPromise = new Promise((resolve, reject) => {
                        const script = document.createElement('script');
                        script.src = url;
                        script.async = true;
                        script.onload = () => resolve(window.paypal);
                        script.onerror = () => reject(new Error('SDK failed to load.'));
                        document.head.appendChild(script);
                    });

                    return this.sdkPromise;
                },

                csrfToken() {
                    return document.querySelector('meta[name="csrf-token"]')?.content || '';
                },

                renderButtons(paypal) {
                    if (this.buttonsRendered) return;

                    const container = document.getElementById('paypal-button-container');
                    if (!container) return;

                    paypal.Buttons({
                        style: { layout: 'vertical', color: 'gold', shape: 'rect', label: 'paypal' },

                        createOrder: () => {
                            this.error = null;

                            return fetch('/software/purchases/' + options.purchase_id + '/paypal', {
                                method: 'POST',
                                headers: {
                                    'Accept': 'application/json',
                                    'X-CSRF-TOKEN': this.csrfToken()
                                }
                            })
                                .then(response => response.json())
                                .then(data => {
                                    if (!data.paypal_order_id) {
                                        throw new Error(data.message || 'PayPal order could not be created.');
                                    }
                                    return data.paypal_order_id;
                                });
                        },

                        onApprove: (data) => {
                            this.status = 'processing';

                            return fetch('/software/purchases/' + options.purchase_id + '/paypal/capture', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'Accept': 'application/json',
                                    'X-CSRF-TOKEN': this.csrfToken()
                                },
                                body: JSON.stringify({ paypal_order_id: data.orderID })
                            })
                                .then(response => response.json())
                                .then(payload => {
                                    if (!payload.confirmed) {
                                        throw new Error(payload.message || 'Payment could not be confirmed.');
                                    }

                                    this.status = 'paid';
                                    window.location.href = payload.confirmation_url;
                                });
                        },

                        onCancel: () => {
                            this.status = 'ready';
                            this.error = 'Payment was cancelled. Your purchase is still saved.';
                        },

                        onError: () => {
                            this.status = 'ready';
                            this.error = 'Something went wrong during payment. Please try again.';
                        }
                    }).render(container).then(() => {
                        this.buttonsRendered = true;
                    });
                }
            }));
        });
    </script>
@endpush
