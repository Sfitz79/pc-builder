<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * THE BUSINESS CATALOGUE, as a flag rather than a second table.
 *
 * WHY A FLAG AND NOT A SEPARATE `business_components` TABLE
 * -------------------------------------------------------
 * Components are shared: a Noctua NH-D15 is the right cooler for a workstation
 * and for a gaming PC, and duplicating it into a second table would create two
 * rows that must be kept in step on price, stock and spec forever - the exact
 * class of drift this project keeps getting bitten by.
 *
 * WHY NULLABLE WITH NO DEFAULT, AND WHAT NULL MEANS
 * ------------------------------------------------
 * `segments` is nullable and has no default. NULL means "gaming only", which is
 * what every one of the 2,703 existing rows is until someone deliberately tags
 * it otherwise.
 *
 * That default direction is the whole point and is not cosmetic:
 *
 *   - The GAMING builder is untouched. Its pools do not filter on `segments` at
 *     all, so no row can disappear from it. Verified by the existing band-floor
 *     tests, which measure real published prices.
 *   - The BUSINESS builder only ever sees rows that carry an explicit
 *     "business" tag. Nothing leaks in by default.
 *
 * The reverse - defaulting everything to business, or treating a missing tag as
 * "suitable for business" - would mean a new RGB gaming case pulled in by an
 * ingest would start appearing on a workstation quote, which is the failure this
 * whole change exists to stop. Fail closed, in the direction that keeps the
 * wrong product away from the customer.
 *
 * A JSON column rather than a boolean so "this part is for both" is expressible
 * without a second flag, and so the value survives being widened to include
 * other verticals later.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('components', 'segments')) {
            return;
        }

        // Nullable, no default: no table rewrite on Postgres, and every
        // existing row reads back as NULL = gaming-only.
        Schema::table('components', function ($table) {
            $table->json('segments')->nullable()->after('active');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('components', 'segments')) {
            return;
        }

        Schema::table('components', function ($table) {
            $table->dropColumn('segments');
        });
    }
};
