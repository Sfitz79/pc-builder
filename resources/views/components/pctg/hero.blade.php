<div {{ $attributes->merge(['class' => 'pctg-hero']) }}>
    <div class="absolute right-0 top-0 h-96 w-96 rounded-full bg-red-600/10 blur-[140px]">
    </div>

    {{ $slot }}
</div>
