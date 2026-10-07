<header class="border-b border-white/5 bg-pctg-background/85 backdrop-blur-xl">
    <div class="mx-auto flex h-16 max-w-[1600px] items-center gap-4 px-4 sm:px-6 lg:px-8">
        <a href="/" class="flex items-center gap-3">
            {{-- REAL brand asset, supplied by the Boss from
                 OneDrive/PCTECHGUY/Marketing/PCTG Branding/pctg glow.png.

                 It arrived on a WHITE background, not transparent, so
                 scripts/prep-boss-assets.cs keys the white out and lifts the
                 black lettering to near-white so it reads on this dark
                 header. Brand red is left untouched. The square icon is
                 cropped from the same file at 512px, so the header and the
                 favicon are unmistakably the same mark.

                 This replaces an invented mark I generated earlier in this
                 session (a red tile with a generic lightning bolt) and a
                 still-earlier CSS zap-square. Neither was the real logo.

                 width/height reserve the box so the header does not shift as
                 the image decodes, and loading="eager" because this is the LCP
                 element on every page. --}}
            <img
                src="{{ asset('img/brand/pctg-mark-512.png') }}"
                alt="PCTechGuy Online"
                width="36"
                height="36"
                loading="eager"
                fetchpriority="high"
                decoding="async"
                class="h-9 w-9 rounded-lg object-cover"
            >
            <span class="hidden leading-tight sm:block">
                <span class="block font-display text-sm font-bold text-white">PCTechGuy Online</span>
                <span class="block text-[11px] uppercase tracking-[0.18em] text-pctg-text-secondary">Get Your Gamers Edge&trade;</span>
            </span>
        </a>

        <nav class="hidden items-center gap-1 lg:flex" aria-label="Primary">
            <x-nav-link
                :href="route('builder')"
                :active="request()->routeIs('builder')"
            >
                PC Builder
            </x-nav-link>

            <x-nav-link
                :href="url('/components')"
                :active="request()->routeIs('components')"
            >
                Components
            </x-nav-link>

            <x-nav-link
                :href="url('/prebuilts')"
                :active="request()->routeIs('prebuilts')"
            >
                Pre-Builts
            </x-nav-link>

            <x-nav-link
                :href="route('software')"
                :active="request()->routeIs('software')"
            >
                Software
            </x-nav-link>

            <x-nav-link
                :href="url('/support')"
                :active="request()->routeIs('support')"
            >
                Support
            </x-nav-link>
        </nav>

        <div class="ml-auto flex items-center gap-3">
            @auth
                <a href="/builder" class="text-sm font-medium text-pctg-text-secondary transition hover:text-white">Dashboard</a>
                <form method="POST" action="/logout">
                    @csrf
                    <button type="submit" class="text-sm font-medium text-pctg-text-secondary transition hover:text-white">Sign out</button>
                </form>
            @else
                <a href="/login" class="text-sm font-medium text-pctg-text-secondary transition hover:text-white">Sign in</a>
                <a href="/register" class="rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-red-500">Get started</a>
            @endauth
        </div>
    </div>
</header>
