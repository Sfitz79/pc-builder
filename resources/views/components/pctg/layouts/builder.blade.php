@props([
    'title' => null,
    'active' => 'builder',
    'buildTotal' => null,
    'selectedCount' => null,
    'buildProgress' => null,
    'live' => false,
])

<!--
    chrome=false: this shell renders its own header and its own footer bar, and
    draws a `fixed` sidebar sized for a full-bleed viewport. The marketing nav +
    max-w-[1600px] container clipped it (measured 2026-10-07).
    livewire=false: resources/js/app.js already imports Alpine and starts it, and
    no view under resources/views/builder uses a Livewire component. Loading
    Livewire too gave the page two Alpine instances and five failed directives.
-->
<x-app-layout :title="$title" :chrome="false" :livewire="false">
    {{-- THE ALPINE SCOPE LIVES HERE, AT THE LAYOUT ROOT.

     It used to live on an inner wrapper inside builder/dashboard.blade.php,
     while this layout rendered its own header, sidebar, mobile drawer and
     checkout footer as SIBLINGS of that wrapper. Anything those siblings
     rendered therefore sat outside the only scope that owned the builder
     state, and Alpine logged:

       Alpine Expression Error: componentModal is not defined
       Alpine Expression Error: categoryLabel is not defined
       Alpine Expression Error: search is not defined
       Alpine Expression Error: filteredComponents is not defined   (x2)

     which also threw uncaught ReferenceErrors.

     THE EARLIER DIAGNOSIS IN THIS FILE WAS WRONG and is retracted. It blamed
     "multiple instances of Alpine" from Livewire. Measured, that is not it:
     with livewire=false only one script tag loads (the Vite bundle),
     window.Livewire is undefined, and calling builderState() directly returns
     a healthy object with all 83 keys including componentModal, search,
     categoryLabel and filteredComponents. The state was always fine; it was
     simply not in scope where the directives were.

     Hoisting x-data="builderState()" to this root gives the header, sidebar,
     mobile drawer, the page slot AND the checkout footer one shared scope,
     which is what the markup always assumed existed.

     Verified in the browser afterwards: builderStateDataKeys goes 0 -> 83 and
     all four errors disappear. --}}
<div class="min-h-screen bg-[#0b0d12] text-white" x-data="builderState()">

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
                {{-- MEASURED 2026-10-08, mobile-first. At a 360px viewport the page
                     scrolled horizontally: documentElement.scrollWidth 388 against
                     clientWidth 360.

                     main is a flex item, and a flex item's default `min-width` is
                     `auto`, which means it refuses to shrink below its own min-content
                     width. Something inside needs 356px, so main could not go below
                     356 + 32px of p-4 = 388px, and the whole document scrolled sideways
                     on the narrowest phones - exactly the case a mobile-first layout
                     has to get right.

                     `min-w-0` removes that floor and lets the content reflow. It does
                     not change the desktop layout: above md the column is 621-1475px
                     wide and far above its min-content, so the floor was never binding
                     there anyway.

                     Verified across 360 / 390 / 768 / 1280 / 1920 / 2560 by
                     scripts/measure-builder-cards.mjs. --}}
                <main
                    class="
                        min-w-0
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
