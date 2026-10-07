<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('brand.name') }} — Sign in</title>

        {{-- This is the Jetstream default layout, so it shipped completely
             off-brand: `config('app.name', 'Laravel')` rendered an EMPTY
             <title> in production (APP_NAME is "" in .env.vercel.prod, and an
             empty string is a present value so the 'Laravel' default never
             fired), it loaded Jetstream's figtree face instead of the brand
             fonts, and it carried no favicon and no social preview.

             A visitor who lands here from an emailed order link is looking at
             the login page - it has to look like the same business as the
             storefront. --}}
        <meta name="description" content="{{ config('brand.description') }}">

        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        <link rel="apple-touch-icon" href="{{ asset('img/brand/pctg-mark-180.png') }}">

        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ config('brand.name') }}">
        <meta property="og:title" content="{{ config('brand.name') }} — Sign in">
        <meta property="og:description" content="{{ config('brand.description') }}">
        <meta property="og:image" content="{{ asset('img/brand/pctg-og.png') }}">
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:image" content="{{ asset('img/brand/pctg-og.png') }}">

        {{-- Brand fonts (Inter + Space Grotesk), matching layouts/seo.blade.php
             and layouts/app.blade.php, replacing Jetstream's figtree. --}}
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <!-- Styles -->
        @livewireStyles
    </head>
    <body>
        <div class="font-sans text-gray-900 dark:text-gray-100 antialiased">
            {{ $slot }}
        </div>

        @livewireScripts
    </body>
</html>
