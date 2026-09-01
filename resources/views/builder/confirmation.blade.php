@props(['order' => null, 'lineItems' => []])

@php
    $invoiceNumber = 'PCTG-' . date('Y') . '-' . strtoupper(substr($order->uuid, 0, 8));
    $currency = strtoupper($order->currency ?? config('pricing.currency', 'GBP'));
    $symbol = $currency === 'USD' ? '$' : '£';
    $money = fn ($value) => $symbol . number_format((float) $value, 2);
    $buildDays = '5–7 working days';
@endphp

<x-app-layout :title="'Invoice ' . $invoiceNumber">
    @push('styles')
        <style>
            @media print {
                @page { size: A4; margin: 14mm; }

                body {
                    background: #ffffff !important;
                    color: #0f172a !important;
                }

                .no-print,
                nav,
                footer,
                .print-toolbar {
                    display: none !important;
                }

                .invoice-sheet {
                    box-shadow: none !important;
                    border: none !important;
                    background: #ffffff !important;
                    color: #0f172a !important;
                    border-radius: 0 !important;
                }

                .invoice-sheet * {
                    border-color: #e2e8f0 !important;
                    color: #0f172a !important;
                }

                .invoice-sheet .muted {
                    color: #475569 !important;
                }

                .print-only {
                    display: block !important;
                }
            }

            @media screen {
                .print-only { display: none; }
            }
        </style>
    @endpush

    <div class="mx-auto max-w-3xl py-6 print:max-w-none print:py-0">
        {{-- Print toolbar --}}
        <div class="print-toolbar mb-6 flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="font-display text-2xl font-bold text-white">Order confirmed</h1>
                <p class="mt-1 text-sm text-pctg-text-secondary">
                    Thank you — your build is now in production and a copy of this invoice is shown below.
                </p>
            </div>
            <div class="flex flex-wrap gap-3">
                <x-pctg.button href="/builder" variant="secondary" size="md">
                    Back to builder
                </x-pctg.button>
                <x-pctg.button variant="primary" size="md" onclick="window.print()">
                    Print / Save as PDF
                </x-pctg.button>
            </div>
        </div>

        {{-- Invoice --}}
        <div class="invoice-sheet overflow-hidden rounded-2xl border border-slate-800 bg-white p-8 text-slate-900 shadow-xl sm:p-10">
            {{-- Header --}}
            <div class="flex flex-wrap items-start justify-between gap-6 border-b border-slate-200 pb-6">
                <div>
                    <p class="font-display text-2xl font-bold tracking-tight">PCTG<span class="text-red-600">&trade;</span></p>
                    <p class="mt-1 text-sm font-medium text-slate-600">PC Tech &amp; Gaming</p>
                    <p class="mt-0.5 text-xs text-slate-500">Get Your Gamers Edge</p>
                </div>
                <div class="text-right">
                    <h2 class="font-display text-xl font-bold uppercase tracking-wide text-slate-900">Invoice</h2>
                    <p class="mt-1 text-sm text-slate-600">No. <span class="font-semibold text-slate-900">{{ $invoiceNumber }}</span></p>
                    <p class="text-sm text-slate-600">Issued {{ $order->paid_at?->format('j F Y') ?? now()->format('j F Y') }}</p>
                    <p class="text-sm text-slate-600">Order ref. <span class="font-mono text-xs">{{ strtoupper($order->uuid) }}</span></p>
                </div>
            </div>

            {{-- Bill to / build details --}}
            <div class="mt-6 grid grid-cols-1 gap-6 sm:grid-cols-2">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Billed to</p>
                    <p class="mt-2 text-sm font-semibold text-slate-900">{{ $order->customer_name ?? 'Customer' }}</p>
                    @if ($order->customer_email)
                        <p class="text-sm text-slate-600">{{ $order->customer_email }}</p>
                    @endif
                    <p class="mt-1 text-xs text-slate-500">Paid via PayPal &middot; {{ $order->paypal_capture_id ? 'Capture ' . strtoupper($order->paypal_capture_id) : 'Approved' }}</p>
                </div>
                <div class="sm:text-right">
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Your build</p>
                    <p class="mt-2 text-sm font-semibold text-slate-900">{{ $order->build?->name ?? 'Custom Build' }}</p>
                    <p class="text-sm text-slate-600">{{ $order->build?->resolution ? $order->build->resolution . ' gaming' : 'Custom PC' }}</p>
                    <p class="mt-1 text-sm text-slate-600">Build &amp; delivery time <span class="font-semibold text-slate-900">5–7 working days</span></p>
                </div>
            </div>

            {{-- Line items --}}
            <table class="mt-8 w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-[0.14em] text-slate-500">
                        <th class="py-2 pr-4 font-semibold">Component</th>
                        <th class="py-2 pr-4 font-semibold">Category</th>
                        <th class="py-2 pr-4 text-right font-semibold">Qty</th>
                        <th class="py-2 text-right font-semibold">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($lineItems as $item)
                        <tr>
                            <td class="py-3 pr-4">
                                <p class="font-semibold text-slate-900">{{ $item['name'] }}</p>
                                @if (!empty($item['detail']))
                                    <p class="text-xs text-slate-500">{{ $item['detail'] }}</p>
                                @endif
                            </td>
                            <td class="py-3 pr-4 capitalize text-slate-600">{{ $item['category'] }}</td>
                            <td class="py-3 pr-4 text-right text-slate-600">1</td>
                            <td class="py-3 text-right font-medium text-slate-900">{{ $money($item['price']) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-3 text-slate-500">No line items recorded.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>

            {{-- Totals --}}
            <div class="mt-6 flex justify-end">
                <dl class="w-full max-w-xs space-y-2 text-sm">
                    <div class="flex items-center justify-between">
                        <dt class="muted text-slate-500">Components</dt>
                        <dd class="font-medium text-slate-900">{{ $money($order->parts_total) }}</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="muted text-slate-500">Build &amp; delivery</dt>
                        <dd class="font-medium text-slate-900">{{ $money($order->build_delivery) }}</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="muted text-slate-500">Subtotal</dt>
                        <dd class="font-medium text-slate-900">{{ $money($order->subtotal) }}</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="muted text-slate-500">PayPal processing fee</dt>
                        <dd class="font-medium text-slate-900">{{ $money($order->paypal_fee) }}</dd>
                    </div>
                    <div class="flex items-center justify-between border-t border-slate-200 pt-3">
                        <dt class="font-semibold text-slate-900">Total paid</dt>
                        <dd class="font-display text-lg font-bold text-slate-900">{{ $money($order->total) }}</dd>
                    </div>
                </dl>
            </div>

            {{-- Delivery + terms --}}
            <div class="mt-8 grid grid-cols-1 gap-6 border-t border-slate-200 pt-6 sm:grid-cols-2">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Build &amp; delivery</p>
                    <ul class="mt-2 space-y-1 text-sm text-slate-600">
                        <li>&bull; Your PC is hand-built, burn-tested and quality checked before dispatch.</li>
                        <li>&bull; Estimated build &amp; delivery time: <span class="font-semibold text-slate-900">{{ $buildDays }}</span> from payment confirmation.</li>
                        <li>&bull; A burn test report is included with every system.</li>
                    </ul>
                </div>
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Terms &amp; warranty</p>
                    <p class="mt-2 text-sm leading-relaxed text-slate-600">
                        This order is subject to our
                        <a href="{{ route('terms') }}" class="font-semibold text-slate-900 underline">Terms of Service</a>
                        and <a href="{{ route('privacy') }}" class="font-semibold text-slate-900 underline">Privacy Policy</a>.
                        Your build includes our standard warranty — full terms are available on the Support page.
                    </p>
                </div>
            </div>

            {{-- Footer --}}
            <div class="mt-8 flex flex-wrap items-center justify-between gap-4 border-t border-slate-200 pt-4">
                <p class="text-xs text-slate-500">PCTG &middot; PC Tech &amp; Gaming &middot; Get Your Gamers Edge&trade;</p>
                <p class="print-only text-xs text-slate-500">Generated {{ now()->format('j F Y, g:ia') }} &middot; Invoice {{ $invoiceNumber }}</p>
                <p class="text-xs text-slate-500">Questions? <a href="{{ route('support') }}" class="font-semibold text-slate-900 underline">Contact support</a></p>
            </div>
        </div>
    </div>
</x-app-layout>
