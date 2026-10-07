<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add first-class identity columns for parts whose NAMES ARE NOT IDENTITY.
 *
 * WHY
 * ---
 * The scraper already fetches these values. ScrapedCatalogSeeder::payload()
 * persisted only two of the six RAM fields and silently dropped the rest, so
 * the catalogue kept several DIFFERENT products under one marketing name:
 *
 *   "Crucial Pro 32 GB"        x2  - both DDR5-6400, both GBP 400
 *   "Corsair Vengeance RGB 32 GB" x17 - DDR5-5200 ... 7200, GBP 371 - 601
 *
 * And the case rows were worse: the scrape captured includedFans, sidePanel,
 * type and powerSupply, and the seeder persisted NONE of it, which is exactly
 * why 197 case rows have no usable discriminator at all.
 *
 * The product URL carries the manufacturer part number
 * (uk.pcpartpicker.com/product/TQjRsY/crucial-pro-32-gb-2-x-16-gb-ddr5-6400-
 * cl38-memory-cp2k16g64c38u5b -> MPN "cp2k16g64c38u5b"), so identity is
 * recoverable without inventing anything.
 *
 * WHY COLUMNS AND NOT MORE specs JSON
 * ----------------------------------
 * socket, wattage and chipset are already dedicated columns for exactly this
 * reason: they are read in queries and in AIRecommendationService. These new
 * fields are the same class - the identifiers a buyer compares first (speed,
 * latency, module layout, interface) - so they belong beside their peers
 * rather than buried in JSON that has to be parsed at every call site.
 *
 * All nullable and additive. Nothing is dropped, nothing is rewritten, and an
 * existing row that has no value simply reads NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('components', function (Blueprint $table) {
            // --- Memory (RAM) -------------------------------------------------
            // "DDR5-6400" / "DDR4-3200". The generation matters on its own:
            // DDR4 and DDR5 cannot be fitted to the same board.
            $table->string('memory_speed')->nullable()->after('chipset');

            // "DDR4" / "DDR5" - the platform constraint, kept separate from the
            // speed because that is what ramGenerationForSocket() matches on.
            $table->string('memory_type')->nullable()->after('memory_speed');

            // CL latency as a NUMBER, not "11.875 ns". RAMComparisonService and
            // the FPS panel compare latency numerically; a formatted string
            // cannot be ordered.
            $table->smallInteger('cas_latency')->nullable()->after('memory_type');

            // "2 x 16GB" - the module layout. Two 16GB sticks and one 32GB
            // stick are different products that both read "32 GB".
            $table->string('module_config')->nullable()->after('cas_latency');

            // --- Storage -----------------------------------------------------
            // "PCIe 4.0" - the interface that decides real sequential speed.
            $table->string('interface')->nullable()->after('module_config');

            // "NVMe" / "SATA" - SATA and NVMe are not interchangeable and the
            // name frequently omits it entirely.
            $table->string('storage_type')->nullable()->after('interface');

            // --- Universal identity -----------------------------------------
            // Manufacturer part number, lifted from the source product URL.
            // This is the true product identity: two rows with the same MPN are
            // the same product listed twice, not two products.
            $table->string('mpn')->nullable()->after('storage_type');

            // --- Case / cooler (persisted for the first time) -----------------
            $table->string('case_type')->nullable()->after('mpn');

            $table->string('side_panel')->nullable()->after('case_type');

            $table->smallInteger('included_fans')->nullable()->after('side_panel');

            $table->string('radiator_size')->nullable()->after('included_fans');
        });
    }

    public function down(): void
    {
        Schema::table('components', function (Blueprint $table) {
            $table->dropColumn([
                'memory_speed', 'memory_type', 'cas_latency', 'module_config',
                'interface', 'storage_type', 'mpn',
                'case_type', 'side_panel', 'included_fans', 'radiator_size',
            ]);
        });
    }
};