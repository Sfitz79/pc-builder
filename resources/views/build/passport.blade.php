{{--
    THE BUILD PASSPORT.

    What this document IS: the locked bill of materials for one build, showing the part, its
    manufacturer and part number, and the price each part carried at the moment the build was
    locked.

    What this document is NOT: a test report. It carries no benchmark, no temperature and no
    memory-test result, because we do not capture those per build today and inventing them
    would be the fastest way to lose a 5-star reputation. That is stated on the page itself
    rather than left for the customer to assume otherwise.
--}}
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Build Passport {{ $build->name }} | PCTechGuyOnline</title>
    <meta name="description" content="The locked component manifest for this PCTechGuyOnline custom gaming PC: every part, its part number, and the price it carried when the build was locked.">
    <style>
        :root { --ink:#14161a; --muted:#5b6472; --line:#e3e6ea; --accent:#c8102e; }
        * { box-sizing: border-box; }
        body { margin:0; padding:32px 20px 64px; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
               color:var(--ink); background:#f6f7f9; line-height:1.55; }
        .sheet { max-width:820px; margin:0 auto; background:#fff; border:1px solid var(--line); border-radius:10px; padding:32px; }
        h1 { font-size:24px; margin:0 0 4px; letter-spacing:-0.01em; }
        .sub { color:var(--muted); font-size:14px; margin:0 0 24px; }
        .slug { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; background:#f0f2f5; padding:2px 7px; border-radius:4px; font-size:13px; }
        table { width:100%; border-collapse:collapse; margin:18px 0 8px; font-size:14px; }
        th { text-align:left; font-size:12px; text-transform:uppercase; letter-spacing:.06em;
             color:var(--muted); border-bottom:2px solid var(--line); padding:8px 6px; }
        td { padding:11px 6px; border-bottom:1px solid var(--line); vertical-align:top; }
        td.cat { white-space:nowrap; font-weight:600; width:130px; }
        td.price { text-align:right; white-space:nowrap; font-variant-numeric:tabular-nums; }
        .mpn { display:block; color:var(--muted); font-size:12px; font-family:ui-monospace,SFMono-Regular,Menlo,monospace; margin-top:2px; }
        tfoot td { border-bottom:none; border-top:2px solid var(--ink); font-weight:700; padding-top:14px; }
        .note { background:#f0f6ff; border-left:3px solid #1c64c8; padding:14px 16px; border-radius:0 6px 6px 0;
                font-size:14px; margin:24px 0 0; }
        .note strong { display:block; margin-bottom:3px; }
        .muted { color:var(--muted); font-size:13px; margin-top:22px; padding-top:16px; border-top:1px solid var(--line); }
        @media print { body { background:#fff; padding:0; } .sheet { border:none; } }
    </style>
</head>
<body>
<div class="sheet">
    <h1>Build Passport</h1>
    <p class="sub">
        {{ $build->name }} &middot; locked {{ $lockedAt ? \Illuminate\Support\Carbon::parse($lockedAt)->format('j F Y, H:i') : '—' }}
        &middot; reference <span class="slug">{{ $build->share_slug }}</span>
    </p>

    <table>
        <thead>
            <tr>
                <th>Part</th>
                <th>Fitted component</th>
                <th style="text-align:right">Price when locked</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lines as $line)
                <tr>
                    <td class="cat">{{ $line['category'] }}</td>
                    <td>
                        {{ $line['name'] }}
                        @if ($line['mpn'])
                            <span class="mpn">Part no. {{ $line['mpn'] }}</span>
                        @endif
                    </td>
                    <td class="price">GBP {{ number_format((float) $line['price'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="3">No components were recorded against this build.</td></tr>
            @endforelse
        </tbody>
        @if ($lines->isNotEmpty())
            <tfoot>
                <tr>
                    <td class="cat">Total</td>
                    <td>Locked component total</td>
                    <td class="price">GBP {{ number_format($lockedTotal, 2) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>

    <div class="note">
        <strong>This is the locked specification.</strong>
        Every price above is the price we paid for that part when this build was locked. Component
        prices move daily &mdash; these do not. If the market price of a part changes, what you were
        quoted and what we fitted is still exactly what this page says.
    </div>

    @unless ($hasTestCapture)
        <div class="note" style="background:#fff8ec; border-left-color:#b26a00;">
            <strong>What this page does not claim.</strong>
            This is a component manifest, not a test report. It carries no benchmark score, no
            temperatures and no memory-test result, because we do not record those per build and
            we will not publish figures we have not measured. Every build is assembled and tested
            before it ships; if you want the testing detail for your machine, ask and we will send it.
        </div>
    @endunless

    <p class="muted">
        PCTechGuyOnline &mdash; custom gaming PCs built and tested in the UK.
        <a href="{{ url('/build/'.$build->share_slug) }}">View this build</a>
    </p>
</div>
</body>
</html>
