{{-- Hero shell.

     The top-right glow used to be an empty decorative div:
     <div class="absolute right-0 top-0 h-96 w-96 rounded-full bg-red-600/10 blur-[140px]"></div>
     which is why the hero had no product in it and the page read as a generic
     SaaS landing page rather than a gaming PC shop. It now carries the real
     machine the Boss supplied.

     The photo arrives with a transparent background already keyed out
     (-removebg-preview), so it sits on the dark hero with no white box.

     ALT TEXT MATTERS HERE: the image is decorative-adjacent, so its alt is a
     plain description of a red and black glass tower PC. It deliberately does
     NOT name a CPU, GPU or any spec, because none of that is verifiable from
     the photograph and inventing it would be a claim we cannot stand behind.

     The soft red bloom is kept BEHIND the photo so it still reads as lit from
     within rather than a flat sticker pasted on the page. --}}
<div {{ $attributes->merge(['class' => 'pctg-hero']) }}>
    <div class="absolute right-0 top-0 h-96 w-96 rounded-full bg-red-600/10 blur-[140px]" aria-hidden="true">
    </div>

    <div class="pointer-events-none absolute -right-8 top-4 hidden w-[32rem] max-w-[55%] select-none lg:block">
        <img
            src="{{ asset('img/hero/pc-hero-src.png') }}"
            alt="A custom PCTG gaming PC: a black mid-tower with a tempered glass side panel and red RGB fans lit inside."
            width="590"
            height="394"
            loading="eager"
            fetchpriority="high"
            decoding="async"
            class="w-full drop-shadow-[0_0_60px_rgba(239,68,68,0.28)]"
        >
    </div>

    {{ $slot }}
</div>
