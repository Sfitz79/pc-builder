{{--
    THIRD-PARTY ASSET CREDITS.

    Renders the credits that config('builder.asset_credits') declares for any
    customer-facing image derived from a third-party 3D asset.

    WHY THIS EXISTS
    ---------------
    App\Services\ThreeD\AssetLicenceGate decides whether an asset may be used
    and returns `attributionRequired`. As of 2026-10-06 nothing read that value:
    it occurred only inside the gate itself. The gate could therefore conclude
    "attribution required" while the site displayed nothing, which is a silent
    licence breach on a storefront rather than a cosmetic defect.

    This partial closes that loop. Credits are declared in config (so the
    obligation is recorded in one reviewable place) and rendered here.

    Rendered only when there is something to credit, so a build with no
    third-party assets shows no empty heading.

    @see \App\Services\ThreeD\AssetLicenceGate
    @see config/builder.php  asset_credits
--}}
@php
    $credits = config('builder.asset_credits', []);
@endphp

@if (is_array($credits) && count($credits) > 0)
    <div class="mt-3 rounded-lg border border-slate-800 bg-slate-900/50 p-3 text-xs text-slate-400">
        <p class="font-semibold uppercase tracking-wide text-slate-300">
            3D asset credits
        </p>

        <ul class="mt-2 space-y-1">
            @foreach ($credits as $key => $credit)
                <li>
                    {{-- Never print a raw licence string from config into HTML
                         without escaping; every field below is escaped. --}}
                    <span class="text-slate-200">{{ $credit['title'] ?? $key }}</span>
                    @if (! empty($credit['author']))
                        by <span class="text-slate-300">{{ $credit['author'] }}</span>
                    @endif
                    @if (! empty($credit['licence']))
                        &middot; <span class="text-slate-300">{{ $credit['licence'] }}</span>
                    @endif
                    @if (! empty($credit['source']))
                        &middot;
                        <a href="{{ $credit['source'] }}"
                           target="_blank"
                           rel="noopener noreferrer"
                           class="text-cyan-400 underline hover:text-cyan-300">source</a>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
@endif