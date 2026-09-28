<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records when a component price was last checked against a live source.
 *
 * Boss directive 2026-09-27: prices must be for live available stock given
 * price fluctuations and current market conditions. The catalogue is a
 * scraped snapshot, so every price carries an age and the quoting/marketing
 * surfaces refuse to publish anything outside the freshness window
 * (PriceIntegrityService::FRESH_DAYS).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('components', 'price_checked_at')) {
            Schema::table('components', function (Blueprint $table) {
                $table->timestamp('price_checked_at')->nullable()->after('price');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('components', 'price_checked_at')) {
            Schema::table('components', function (Blueprint $table) {
                $table->dropColumn('price_checked_at');
            });
        }
    }
};
