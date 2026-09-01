@props([
    'title',
    'subtitle' => null,
    'description' => null,
    'icon' => null,
])

<div class="flex items-start justify-between gap-4">
    <div class="min-w-0">
        @if ($icon)
            <div class="mb-3 flex h-11 w-11 items-center justify-center rounded-2xl bg-pctg-primary/10 text-pctg-primary-hover ring-1 ring-pctg-primary/30">
                <x-pctg.icon :name="$icon" class="h-5 w-5" />
            </div>
        @endif

        <h2 class="text-2xl font-bold">
            {{ $title }}
        </h2>

        @if ($description)
            <p class="mt-2 text-slate-400">
                {{ $description }}
            </p>
        @elseif ($subtitle)
            <p class="mt-2 text-slate-400">
                {{ $subtitle }}
            </p>
        @endif
    </div>

    @isset($action)
        <div class="shrink-0">
            {{ $action }}
        </div>
    @endisset
</div>
