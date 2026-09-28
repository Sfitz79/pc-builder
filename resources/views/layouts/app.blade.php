@props([
    'title' => null,
    'description' => 'PCTG Builder — configure a custom gaming PC to your own spec, assembled and tested in the UK. Free UK delivery, warranty and lifetime remote support.',
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="no-js dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script>document.documentElement.classList.remove('no-js');</script>

    <title>{{ ($title ? $title . ' | ' : '') . config('app.name', 'PCTG Builder') }}</title>

    <meta name="description" content="{{ $description }}">

    <link rel="canonical" href="{{ $canonical ?? url()->current() }}">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ config('app.name', 'PCTG Builder') }}">
    <meta property="og:url" content="{{ $canonical ?? url()->current() }}">
    <meta property="og:title" content="{{ ($title ? $title . ' | ' : '') . config('app.name', 'PCTG Builder') }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:locale" content="en_GB">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ ($title ? $title . ' | ' : '') . config('app.name', 'PCTG Builder') }}">
    <meta name="twitter:description" content="{{ $description }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @include('partials.schema-localbusiness')

    @stack('schema')

    @livewireStyles
    @stack('styles')
</head>
<body class="min-h-screen bg-pctg-background font-sans text-pctg-text-primary">
    @include('navigation-menu')

    <div class="mx-auto w-full max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8">
        {{ $slot }}
    </div>

    <footer class="border-t border-white/5 bg-pctg-surface">
        <div class="mx-auto flex max-w-[1600px] flex-wrap items-center justify-between gap-4 px-4 py-6 sm:px-6 lg:px-8">
            <p class="text-sm text-pctg-text-secondary">&copy; {{ date('Y') }} PCTG Builder. Get Your Gamers Edge&trade;</p>
            <div class="flex items-center gap-6 text-sm text-pctg-text-secondary">
                <a href="{{ route('privacy') }}" class="transition hover:text-white">Privacy</a>
                <a href="{{ route('terms') }}" class="transition hover:text-white">Terms</a>
                <a href="{{ route('support') }}" class="transition hover:text-white">Contact</a>
            </div>
        </div>
    </footer>

    @livewireScripts
    @stack('scripts')
</body>
</html>
