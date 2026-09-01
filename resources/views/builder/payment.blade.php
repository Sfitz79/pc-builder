<x-pctg.layouts.builder
    title="Payment"
    active="checkout"
    :live="true"
    :build-total="null"
>
    <x-pctg.section-heading
        icon="credit-card"
        title="Payment"
        subtitle="Pay securely with PayPal — your build enters production as soon as payment clears."
    >
        <x-slot:action>
            <x-pctg.badge variant="success" dot x-show="$store.checkout.paid">Paid</x-pctg.badge>
        </x-slot:action>
    </x-pctg.section-heading>

    {{-- Order creation in progress --}}
    <div x-show="$store.checkout.creating" class="mb-5">
        <x-pctg.card class="p-6">
            <div class="flex items-center gap-4">
                <div class="h-5 w-5 animate-spin rounded-full border-2 border-pctg-primary-hover border-t-transparent"></div>
                <p class="text-sm text-pctg-text-secondary">Preparing your payment…</p>
            </div>
        </x-pctg.card>
    </div>

    {{-- Error --}}
    <div x-show="$store.checkout.error && !$store.checkout.creating" class="mb-5">
        <x-pctg.card class="border border-red-500/20 p-5">
            <p class="text-sm font-medium text-red-400" x-text="$store.checkout.error"></p>
            <div class="mt-4 flex flex-wrap gap-3">
                <x-pctg.button variant="primary" size="sm" x-on:click="$store.checkout.resetOrder()">Start again</x-pctg.button>
                <x-pctg.button href="/builder" variant="secondary" size="sm">Back to builder</x-pctg.button>
            </div>
        </x-pctg.card>
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3" x-show="!$store.checkout.error">
        <div class="space-y-5 lg:col-span-2">
            {{-- Paid state --}}
            <x-pctg.card class="p-6" x-show="$store.checkout.paid">
                <div class="flex items-center gap-4">
                    <x-pctg.icon name="check-circle" class="h-8 w-8 text-pctg-success" />
                    <div>
                        <h2 class="font-display text-lg font-bold text-white">Payment complete</h2>
                        <p class="mt-1 text-sm text-pctg-text-secondary">Thanks — your build is now in production and will ship in 5–7 working days.</p>
                    </div>
                </div>
                <div class="mt-5">
                    <a :href="'/builder/orders/' + ($store.checkout.order ? $store.checkout.order.order_id : '') + '/confirmed'" class="inline-flex items-center gap-2 px-8 py-4 text-lg pctg-button power-pulse">
                        View invoice
                    </a>
                </div>
            </x-pctg.card>

            {{-- PayPal pay box --}}
            <x-pctg.card :padded="false" class="overflow-hidden" x-show="!$store.checkout.paid">
                <div class="border-b border-white/5 p-6">
                    <h2 class="flex items-center gap-2 font-display text-lg font-bold text-white">
                        <x-pctg.icon name="wallet" class="h-5 w-5 text-pctg-primary-hover" />
                        Pay with PayPal
                    </h2>
                </div>

                <div class="space-y-4 p-6">
                    <p class="text-sm text-pctg-text-secondary">
                        Click the button below to complete your purchase securely. You will be taken to PayPal,
                        then returned here automatically. No card details are stored on our servers.
                    </p>

                    <div id="paypal-button-container" class="min-h-[3rem]"></div>

                    <div
                        x-show="$store.checkout.order && !$store.checkout.paypalConfigured && !$store.checkout.creating"
                        class="rounded-xl border border-amber-500/20 bg-amber-500/5 p-4 text-sm text-amber-300"
                    >
                        Online payment is not enabled yet — this build is locked in, but you can't complete the
                        purchase until PayPal is configured. Your selection stays saved.
                    </div>

                    <p class="text-center text-xs text-pctg-text-secondary" x-show="$store.checkout.creating">
                        Preparing your payment…
                    </p>
                </div>
            </x-pctg.card>

            <x-pctg.card class="p-6" x-show="!$store.checkout.paid">
                <h2 class="flex items-center gap-2 font-display text-lg font-bold text-white">
                    <x-pctg.icon name="shield-check" class="h-5 w-5 text-pctg-success" />
                    What happens next
                </h2>
                <ul class="mt-4 space-y-3 text-sm text-pctg-text-secondary">
                    <li class="flex gap-3"><span class="font-bold text-white">1.</span> We build and burn-test your PC — <span class="font-semibold text-white">5–7 working days</span>.</li>
                    <li class="flex gap-3"><span class="font-bold text-white">2.</span> Every system ships with a burn test report and full cable management.</li>
                    <li class="flex gap-3"><span class="font-bold text-white">3.</span> Your invoice and delivery tracking are emailed to you.</li>
                </ul>
            </x-pctg.card>
        </div>

        <x-pctg.card :padded="false" class="h-fit overflow-hidden lg:sticky lg:top-24">
            <div class="space-y-4 p-6">
                <p class="text-xs font-medium uppercase tracking-[0.14em] text-pctg-text-secondary">Order summary</p>
                <p class="font-display text-4xl font-bold text-white" x-text="$store.checkout.amount ? $store.checkout.money($store.checkout.amount.total) : '…'"></p>

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

                <p class="border-t border-white/5 pt-4 text-center text-xs text-pctg-text-secondary">
                    By paying you agree to our
                    <a href="{{ route('terms') }}" class="underline transition hover:text-white">Terms of Service</a>
                    &middot; Build &amp; delivery takes 5–7 working days
                </p>
            </div>
        </x-pctg.card>
    </div>
</x-pctg.layouts.builder>
