@props([
    'title',
    'message'
])

<div class="pctg-card text-center">
    <h3 class="text-xl font-semibold">
        {{ $title }}
    </h3>

    <p class="mt-2 text-slate-400">
        {{ $message }}
    </p>
</div>
