@props([
    'previous' => null,
    'previousTitle' => null,
    'next' => null,
    'nextTitle' => null,
])

<section class="mt-16 flex justify-between gap-4">

    @if ($previous)
        <a href="{{ url($previous) }}" class="pctg-button-secondary">
            ← {{ $previousTitle }}
        </a>
    @else
        <div></div>
    @endif

    @if ($next)
        <a href="{{ url($next) }}" class="pctg-button-secondary">
            {{ $nextTitle }} →
        </a>
    @endif

</section>
