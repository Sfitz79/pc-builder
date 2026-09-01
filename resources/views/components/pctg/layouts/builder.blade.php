@props([
    'title' => null,
    'active' => 'builder',
    'buildTotal' => null,
    'selectedCount' => null,
    'buildProgress' => null,
    'live' => false,
])

<x-app-layout :title="$title">
    <div class="min-h-screen bg-[#0b0d12] text-white" x-data>

        {{-- Ambient Background --}}
        <div class="fixed inset-0 overflow-hidden pointer-events-none">
            <div
                class="absolute top-10 left-1/3 h-[500px] w-[500px] rounded-full bg-red-600/10 blur-[150px]"
            ></div>

            <div
                class="absolute bottom-0 right-0 h-[500px] w-[500px] rounded-full bg-red-500/10 blur-[150px]"
            ></div>
        </div>

        {{-- Header --}}
        <x-pctg.header :active="$active" />

        {{-- Mobile Nav --}}
        <x-pctg.mobile-drawer />

        {{-- Metrics --}}
        <x-pctg.metrics-bar :build-total="$buildTotal" :live="$live" />

        <div class="relative">
            <div class="flex">
                {{-- Desktop Sidebar --}}
                <x-pctg.sidebar />

                {{-- Main Content --}}
                <main
                    class="
                        flex-1
                        p-4
                        pb-36
                        md:p-6
                        md:ml-72
                    "
                >
                    {{ $slot }}
                </main>
            </div>
        </div>

        {{-- Checkout Footer --}}
        <x-pctg.checkout-footer :build-total="$buildTotal" :live="$live" />

    </div>
</x-app-layout>
