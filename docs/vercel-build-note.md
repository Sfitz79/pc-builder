# vercel.json — why there is no `public/build` entry

**Do not add `{ "src": "/public/build/**", "use": "@vercel/static" }` back to the
`builds` array in `vercel.json`. It silently breaks the frontend build.**

## What happens

`vercel.json` sets `"buildCommand": "npm run build"`. That command only runs if
the `builds` array does **not** claim the frontend output.

When a `builds` array exists, Vercel treats the project as using the Builds API
and **ignores `buildCommand` entirely**. So adding a `builds` entry that matches
`public/build/**` — which is exactly where `vite build` writes — means the build
command never executes.

`public/build` is also gitignored (`.gitignore:3`), so it is never uploaded from
git either. The result is that Vercel serves whatever stale copy of the bundle
was sitting in the deploying working directory.

## How this was found

On 2026-10-07 a deploy shipped a JS bundle dated **06/10** while the Blade views
on the same page showed edits made that day. The Blade changes were live; the
JavaScript was months out of date. Verified by fetching the served bundle and
finding the fabricated landing-page prices that had already been removed from
source.

It is a silent failure in the worst way: the deployment reports `READY`, the
HTML visibly changes, and the JS is quietly stale. It had been hiding behind
every previous deploy.

## Why it is fine without that entry

Vite's output is already reachable. The `routes` array in `vercel.json` maps:

```
/build/assets/(.*)        -> /public/build/assets/$1
/build/manifest.json      -> /public/build/manifest.json
```

Those routes are enough to serve the built assets and the manifest that
`@vite(...)` reads. No `builds` entry is needed, and leaving the path unclaimed
is what allows `buildCommand` to take effect.

## How to verify a deploy is actually current

Do not trust `READY`. Check the bundle:

```powershell
$h = (Invoke-WebRequest https://pctechguy.app/ -UseBasicParsing).Content
$src = [regex]::Match($h, '/build/assets/app-[^"]+\.js').Value
$js  = (Invoke-WebRequest ("https://pctechguy.app" + $src) -UseBasicParsing).Content
# assert a string that only exists in the current source
if ($js -match 'Parts, build, testing, delivery and warranty') { 'current' } else { 'STALE BUNDLE' }
```

Then also check the hashed filename in the served HTML against the local build:

```powershell
Get-ChildItem public\build\assets\app-*.js | Select-Object Name, LastWriteTime
```
