# vercel.json — why the frontend bundle must be built before deploying

## The failure this records

On 2026-10-07 a deploy shipped a JS bundle dated **06/10** while the Blade views
on the same page showed edits made that day. The Blade changes were live; the
JavaScript was months out of date. Verified by fetching the served bundle and
finding landing-page prices that had already been removed from source.

It is a silent failure in the worst way: the deployment reports `READY`, the
HTML visibly changes, and the JS is quietly stale. It had been hiding behind
every previous deploy.

## Why it happens

`vercel.json` declares an explicit `builds` array. **When a `builds` array is
present, Vercel ignores the top-level `buildCommand`** and drives the build
entirely from `builds`. So `"buildCommand": "npm run build"` in that file does
nothing at all.

`vite build` writes to `public/build`, which is gitignored (`.gitignore:3`).
So on a GitHub-triggered deploy the freshly built assets are never uploaded,
and the site serves whatever stale bundle was last uploaded.

## What was tried and REJECTED

**Removing `{ "src": "/public/build/**", "use": "@vercel/static" }` from `builds`.**

The reasoning was that this entry claims `vite`'s output path, and leaving it
unclaimed might let `buildCommand` run. It does let `buildCommand` run — the
served HTML immediately began referencing the correctly rebuilt
`app-CDQ_TAHu.js`. **But every asset then 404'd**, because with no `builds`
entry owning `/public/build`, the `routes` entry

```
{ "src": "/build/assets/(.*)", "dest": "/public/build/assets/$1" }
```

had no built output to point at. New manifest, missing files.

The entry was restored. Do not remove it again.

## How this is actually handled

`public/build/**` is served by the `@vercel/static` build entry, and
`npm run build` is run **before** deploying so the directory being uploaded is
current. Because a `vercel --prod` upload takes the working directory, the
freshly built assets go up with it.

The residual risk is a GitHub-triggered deploy, which would not have the built
assets because they are gitignored. Closing that properly means either
committing `public/build` or moving off the legacy `builds` config — that is a
separate piece of work and should be done deliberately, not as a drive-by.

## Verify every deploy is actually current

Do not trust `READY`. Check the served bundle against the local build.

```powershell
# 1. served HTML should reference a bundle hash that matches local
$h = (Invoke-WebRequest https://pctechguy.app/ -UseBasicParsing).Content
$src = [regex]::Match($h, '/build/assets/app-[^"]+\.js').Value
$src
Get-ChildItem public\build\assets\app-*.js | Select-Object Name, LastWriteTime

# 2. and that asset must actually be fetchable
$js = (Invoke-WebRequest ("https://pctechguy.app" + $src) -UseBasicParsing).Content
if ($js -match 'Parts, build, testing, delivery and warranty') { 'bundle CURRENT' } else { 'bundle STALE' }
```

Step 2 matters as much as step 1. Step 1 can pass while every asset 404s.

