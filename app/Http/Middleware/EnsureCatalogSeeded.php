<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureCatalogSeeded
{
    /**
     * One-shot runtime schema + catalogue sync. When MIGRATE_ON_DEPLOY is set,
     * any pending migrations are applied; when SEED_CATALOG_ONCE is set and the
     * components table holds no scraped (PCPP-*) rows, the current market
     * catalogue is imported. This lets fresh deployments that skip build-time
     * composer scripts still ship the full schema and part list. Fail-safe:
     * never breaks a request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (env('MIGRATE_ON_DEPLOY') === '1') {
            try {
                Artisan::call('migrate', ['--force' => true]);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        if (env('SEED_CATALOG_ONCE') === '1') {
            try {
                $hasScraped = DB::table('components')->where('sku', 'like', 'PCPP-%')->exists();

                if (! $hasScraped) {
                    (new \Database\Seeders\ScrapedCatalogSeeder())->runFast();
                }
            } catch (QueryException $e) {
                report($e);
            }
        }

        return $next($request);
    }
}
