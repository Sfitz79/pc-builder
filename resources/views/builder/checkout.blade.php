<x-pctg.layouts.builder
    title="Review & Checkout"
    active="checkout"
    :live="true"
    :build-total="null"
>
    <x-pctg.section-heading
        icon="wallet"
        title="Review & Checkout"
        subtitle="Confirm your selections before placing your order"
    >
        <x-slot:action>
            <x-pctg.badge variant="success" dot x-show="$store.checkout.hasOrder">Build ready</x-pctg.badge>
        </x-slot:action>
    </x-pctg.section-heading>

    {{-- Order creation in progress --}}
    <div x-show="$store.checkout.creating" class="mb-5">
        <x-pctg.card class="p-6">
            <div class="flex items-center gap-4">
                <div class="h-5 w-5 animate-spin rounded-full border-2 border-pctg-primary-hover border-t-transparent"></div>
                <p class="text-sm text-pctg-text-secondary">Locking in your components and prices…</p>
            </div>
        </x-pctg.card>
    </div>

    {{-- Server error --}}
    <div x-show="$store.checkout.error && !$store.checkout.creating" class="mb-5">
        <x-pctg.card class="border border-red-500/20 p-5">
            <p class="text-sm font-medium text-red-400" x-text="$store.checkout.error"></p>
            <div class="mt-4 flex flex-wrap gap-3">
                <x-pctg.button variant="primary" size="sm" x-on:click="$store.checkout.createOrder()">Try again</x-pctg.button>
                <x-pctg.button href="/builder" variant="secondary" size="sm">Back to builder</x-pctg.button>
            </div>
        </x-pctg.card>
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3" x-show="!$store.checkout.error">
        <div class="space-y-5 lg:col-span-2">
            <x-pctg.card :padded="false" class="overflow-hidden">
                <div class="border-b border-white/5 p-6">
                    <h2 class="flex items-center gap-2 font-display text-lg font-bold text-white">
                        <x-pctg.icon name="shopping-cart" class="h-5 w-5 text-pctg-primary-hover" />
                        Selected components
                    </h2>
                </div>

                <ul class="divide-y divide-white/5" x-show="$store.checkout.lineItems.length">
                    <template x-for="item in $store.checkout.lineItems" :key="'item-' + item.category">
                        <li class="flex items-center gap-4 p-5">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-pctg-elevated text-pctg-text-secondary">
                                <x-pctg.icon name="cpu" class="h-5 w-5" x-show="item.category === 'cpu'" />
                                <x-pctg.icon name="layers" class="h-5 w-5" x-show="item.category === 'motherboard'" />
                                <x-pctg.icon name="gpu" class="h-5 w-5" x-show="item.category === 'gpu'" />
                                <x-pctg.icon name="memory-stick" class="h-5 w-5" x-show="item.category === 'ram'" />
                                <x-pctg.icon name="hard-drive" class="h-5 w-5" x-show="item.category === 'storage'" />
                                <x-pctg.icon name="power" class="h-5 w-5" x-show="item.category === 'psu'" />
                                <x-pctg.icon name="computer" class="h-5 w-5" x-show="item.category === 'case'" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold text-white" x-text="item.name"></p>
                                <p class="mt-0.5 text-xs text-pctg-text-secondary" x-text="item.detail || item.category"></p>
                            </div>
                            <p class="shrink-0 font-display text-sm font-bold text-white" x-text="$store.checkout.money(item.price)"></p>
                        </li>
                    </template>
                </ul>

                <div x-show="!$store.checkout.lineItems.length && !$store.checkout.creating" class="p-10 text-center">
                    <p class="text-sm text-pctg-text-secondary">No components are locked in yet.</p>
                    <div class="mt-4">
                        <x-pctg.button href="/builder" variant="secondary" size="sm">Back to the builder</x-pctg.button>
                    </div>
                </div>
            </x-pctg.card>

            <x-pctg.card class="p-6">
                <h2 class="font-display text-lg font-bold text-white">Compatibility</h2>
                <p class="mt-1 text-sm text-pctg-text-secondary">Every PCTG build is verified before it is locked in.</p>
                <div class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="flex items-center gap-3 rounded-2xl border border-white/5 bg-pctg-elevated/60 p-4">
                        <x-pctg.icon name="shield-check" class="h-5 w-5 shrink-0 text-pctg-success" />
                        <p class="text-sm text-pctg-text-secondary">Socket, wattage and clearance checks passed</p>
                    </div>
                    <div class="flex items-center gap-3 rounded-2xl border border-white/5 bg-pctg-elevated/60 p-4">
                        <x-pctg.icon name="gauge" class="h-5 w-5 shrink-0 text-pctg-warning" />
                        <p class="text-sm text-pctg-text-secondary">Performance estimated for your resolution</p>
                    </div>
                </div>
            </x-pctg.card>
        </div>

        <x-pctg.card :padded="false" class="h-fit overflow-hidden lg:sticky lg:top-24">
            <div class="space-y-4 p-6">
                <div>
                    <p class="text-xs font-medium uppercase tracking-[0.14em] text-pctg-text-secondary">Total cost</p>
                    <p class="mt-1 font-display text-4xl font-bold text-white" x-text="$store.checkout.amount ? $store.checkout.money($store.checkout.amount.total) : '…'"></p>
                    <p class="mt-1 text-sm text-pctg-text-secondary">Built &amp; delivered in <span class="font-semibold text-white">5–7 working days</span></p>
                </div>

                <dl class="space-y-2 border-t border-white/5 pt-4 text-sm">
                    <div class="flex items-center justify-between">
                        <dt class="text-pctg-text-secondary">Components</dt>
                        <dd class="font-medium text-white" x-text="$store.checkout.amount ? $store.checkout.money($store.checkout.amount.parts_total) : '…'"></dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-pctg-text-secondary">Build &amp; delivery</dt>
                        <dd class="font-medium text-white" x-text="$store.checkout.amount ? $store.checkout.money($store.checkout.amount.build_delivery) : '…'"></dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-pctg-text-secondary">Subtotal</dt>
                        <dd class="font-medium text-white" x-text="$store.checkout.amount ? $store.checkout.money($store.checkout.amount.subtotal) : '…'"></dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-pctg-text-secondary">PayPal fee</dt>
                        <dd class="font-medium text-pctg-success" x-text="$store.checkout.amount ? $store.checkout.money($store.checkout.amount.paypal_fee) : '…'"></dd>
                    </div>
                </dl>

                <template x-if="$store.checkout.paid && $store.checkout.confirmationUrl">
                    <a :href="'/builder/orders/' + ($store.checkout.order ? $store.checkout.order.order_id : '') + '/confirmed'" class="inline-flex items-center gap-2 px-8 py-4 text-lg pctg-button power-pulse">
                        View your invoice
                    </a>
                </template>

                <template x-if="!$store.checkout.paid">
                    <a :href="'/builder/checkout/payment?order=' + ($store.checkout.order ? $store.checkout.order.order_id : '')" class="inline-flex items-center gap-2 px-8 py-4 text-lg pctg-button power-pulse">
                        <x-pctg.icon name="credit-card" class="h-5 w-5" /> Continue to payment
                    </a>
                </template>

                <p class="text-center text-xs text-pctg-text-secondary">
                    Secure checkout &middot; See our <a href="{{ route('terms') }}" class="underline transition hover:text-white">Terms of Service</a>
                </p>
            </div>
        </x-pctg.card>
    </div>
</x-pctg.layouts.builder>
