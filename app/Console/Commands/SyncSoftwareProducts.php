<?php

namespace App\Console\Commands;

use App\Models\SoftwareProduct;
use App\Services\MetenziException;
use App\Services\MetenziService;
use Illuminate\Console\Command;

class SyncSoftwareProducts extends Command
{
    protected $signature = 'software:sync {--force} {--limit=200}';

    protected $description = 'Import the Metenzi product catalogue into the software store';

    public function handle(MetenziService $metenzi): int
    {
        if (! $metenzi->configured()) {
            $this->error('Metenzi is not configured (METENZI_API_KEY missing).');

            return self::FAILURE;
        }

        try {
            $products = $metenzi->products(retrieveAll: true, limit: (int) $this->option('limit'));
        } catch (MetenziException $e) {
            $this->error('Could not fetch products: ' . $e->getMessage());

            return self::FAILURE;
        }

        $count = 0;
        $rate = $metenzi->gbpRate();

        foreach ($products as $product) {
            $id = (string) ($product['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $retailPrice = (float) ($product['retailPrice'] ?? $product['b2bPrice'] ?? 0);
            $stock = (int) ($product['stock'] ?? 0);
            $status = (string) ($product['status'] ?? 'active');

            SoftwareProduct::updateOrCreate(
                ['metenzi_product_id' => $id],
                [
                    'sku' => (string) ($product['sku'] ?? ''),
                    'name' => (string) ($product['name'] ?? ''),
                    'category' => $product['category'] ?? null,
                    'platform' => $product['platform'] ?? null,
                    'description' => $product['description'] ?? null,
                    'short_description' => $product['shortDescription'] ?? null,
                    'retail_price' => $retailPrice,
                    'retail_price_cents' => (int) ($product['retailPriceCents'] ?? 0),
                    'gbp_price' => round($retailPrice * $rate, 2),
                    'stock' => $stock,
                    'active' => $status === 'active' && $stock > 0,
                    'warranty_days' => $product['warrantyDays'] ?? null,
                    'image_url' => $product['imageUrl'] ?? null,
                    'instructions' => $product['instructions'] ?? null,
                    'status' => $status,
                ]
            );

            $count++;
        }

        $this->info("Synced {$count} software products.");

        return self::SUCCESS;
    }
}
