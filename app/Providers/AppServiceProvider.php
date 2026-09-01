<?php

namespace App\Providers;

use App\Database\NeonPostgresConnector;
use App\Models\Benchmark;
use App\Observers\BenchmarkObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind('db.connector.pgsql', fn () => new NeonPostgresConnector);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Benchmark::observe(BenchmarkObserver::class);
    }
}
