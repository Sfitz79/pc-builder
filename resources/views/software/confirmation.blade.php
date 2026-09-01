@extends('layouts.seo')

@section('title', 'Your Key — ' . $purchase->product_name . ' | PCTG Builder')

@section('description', 'Your software purchase confirmation and key delivery.')

@section('content')
    <div class="mx-auto max-w-3xl" x-data="softwareKeys">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-pctg-primary-hover">Order complete</p>
                <h1 class="mt-2 font-display text-2xl font-bold text-white">
                    @if ($purchase->isFulfilled())
                        Your key is ready
                    @elseif ($purchase->status === 'paid' || $purchase->status === 'awaiting_fulfilment')
                        Payment received
                    @else
                        Order {{ $purchase->status }}
                    @endif
                </h1>
            </div>
            <span class="text-xs text-pctg-text-secondary">Order {{ $purchase->uuid }}</span>
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

        @if ($purchase->isFulfilled())
            <div class="mt-6 rounded-2xl border border-pctg-success/25 bg-pctg-success/10 p-6">
                <h3 class="flex items-center gap-2 font-display text-lg font-bold text-white">
                    <x-pctg.icon name="check-circle" class="h-5 w-5 text-pctg-success" />
                    @if (count($purchase->keys) === 1)
                        Here is your license key
                    @else
                        Here are your license keys
                    @endif
                </h3>

                <div class="mt-5 space-y-4">
                    @foreach ($purchase->keys as $key)
                        <div class="rounded-xl border border-white/10 bg-pctg-surface p-4">
                            <div class="flex flex-wrap items-center gap-3">
                                <code
                                    x-ref="key{{ $loop->index }}"
                                    class="min-w-0 flex-1 select-all break-all rounded-lg bg-black/30 px-3 py-2 font-mono text-sm text-pctg-success"
                                >{{ $key['code'] }}</code>
                                <button
                                    type="button"
                                    x-on:click="copy($refs.key{{ $loop->index }})"
                                    class="inline-flex items-center gap-2 rounded-xl border border-white/10 px-3 py-2 text-sm font-medium text-pctg-text-secondary transition hover:border-white/25 hover:text-white"
                                >
                                    <x-pctg.icon name="copy" class="h-4 w-4" />
                                    <span x-text="copied === {{ $loop->index }} ? 'Copied!' : 'Copy'"></span>
                                </button>
                            </div>
                            @if (($key['type'] ?? 'text/plain') !== 'text/plain')
                                <p class="mt-2 text-xs text-pctg-text-secondary">
                                    {{ $key['type'] === 'code/text' ? 'Activation code' : $key['type'] }}
                                </p>
                            @endif
                        </div>
                    @endforeach
                </div>

                <p class="mt-5 flex items-center gap-2 text-xs text-pctg-text-secondary">
                    <x-pctg.icon name="shield-check" class="h-4 w-4 text-pctg-success" />
                    Keep this page safe — the key is shown once and won't be emailed. Write it down before you close the tab.
                </p>
            </div>
        @else
            <div class="mt-6 rounded-2xl border border-white/5 bg-pctg-surface p-6">
                <h3 class="flex items-center gap-2 font-display text-lg font-bold text-white">
                    <x-pctg.icon name="clock" class="h-5 w-5 text-amber-300" />
                    Your key is on its way
                </h3>
                <p class="mt-2 text-sm text-pctg-text-secondary">
                    @if ($purchase->status === 'backorder')
                        The supplier is currently out of stock for this product. The key will be delivered automatically
                        as soon as stock is available.
                    @else
                        We're fetching your key from the fulfilment network. It usually arrives within a minute — reload
                        this page shortly, or request it again below.
                    @endif
                </p>

                @if (config('metenzi.api_key'))
                    <button
                        type="button"
                        x-on:click="retry"
                        x-bind:disabled="retrying"
                        class="mt-5 inline-flex items-center gap-2 rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-red-500 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        <span x-show="!retrying">Check again for my key</span>
                        <span x-show="retrying" class="inline-flex items-center gap-2">
                            <span class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                            Checking…
                        </span>
                    </button>
                @endif

                <p class="mt-4 flex items-center gap-2 text-xs text-pctg-text-secondary">
                    <x-pctg.icon name="info" class="h-4 w-4" />
                    Order {{ $purchase->uuid }} &middot; You can reopen this page any time from the same device and browser.
                </p>
            </div>
        @endif

        @if (session('error'))
            <div class="mt-6 rounded-xl border border-red-500/20 bg-red-500/5 p-4 text-sm text-red-300">
                {{ session('error') }}
            </div>
        @endif
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('softwareKeys', () => ({
                copied: null,
                retrying: false,

                copy(el) {
                    const range = document.createRange();
                    range.selectNodeContents(el);
                    const selection = window.getSelection();
                    selection.removeAllRanges();
                    selection.addRange(range);

                    const ok = document.execCommand('copy');
                    selection.removeAllRanges();

                    if (ok) {
                        this.copied = this.findIndex(el);
                        setTimeout(() => { this.copied = null; }, 1800);
                    }
                },

                findIndex(el) {
                    const parent = el.closest('.rounded-xl');
                    const items = Array.from(document.querySelectorAll('.rounded-xl'));
                    return items.indexOf(parent);
                },

                async retry() {
                    if (this.retrying) return;
                    this.retrying = true;

                    try {
                        const response = await fetch(
                            '/software/purchases/{{ $purchase->uuid }}/fulfil',
                            { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' } }
                        );
                        const data = await response.json();

                        if (data.fulfilled) {
                            window.location.reload();
                        } else {
                            alert(data.message || 'The key is not available yet. Please check back in a few minutes.');
                        }
                    } catch (e) {
                        alert('Could not reach the server. Please try again.');
                    } finally {
                        this.retrying = false;
                    }
                }
            }));
        });
    </script>
@endpush
