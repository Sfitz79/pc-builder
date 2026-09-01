@props(['active' => 'builder'])

@php
    $links = [
        ['label' => 'Builder', 'href' => '/builder', 'key' => 'builder'],
        ['label' => 'Components', 'href' => '/components', 'key' => 'components'],
        ['label' => 'Pre-Builts', 'href' => '/prebuilts', 'key' => 'prebuilts'],
        ['label' => 'Support', 'href' => '/support', 'key' => 'support'],
    ];
@endphp

<nav class="hidden items-center gap-1 lg:flex" aria-label="Primary">
    @foreach ($links as $link)
        <a
            href="{{ $link['href'] }}"
            class="rounded-xl px-4 py-2 text-sm font-medium transition-colors {{ $active === $link['key'] ? 'bg-pctg-elevated text-white' : 'text-pctg-text-secondary hover:bg-pctg-elevated hover:text-white' }}"
            @if ($active === $link['key']) aria-current="page" @endif
        >{{ $link['label'] }}</a>
    @endforeach
</nav>
