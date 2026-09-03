<?php

namespace Database\Seeders;

use App\Models\Component;
use Illuminate\Database\Seeder;

class ChipsetBackfillSeeder extends Seeder
{
    public function run(): void
    {
        // GPU chipsets from specs JSON
        $gpus = Component::whereHas('category', fn ($q) => $q->where('slug', 'gpu'))
            ->whereNotNull('specs')
            ->get();

        $gpuCount = 0;
        foreach ($gpus as $gpu) {
            $specs = $gpu->specs ?? [];
            $chipset = $specs['chipset'] ?? null;
            if ($chipset && ! $gpu->chipset) {
                $gpu->chipset = $chipset;
                $gpu->save();
                $gpuCount++;
            }
        }

        // CPU chipsets from model name
        $cpus = Component::whereHas('category', fn ($q) => $q->where('slug', 'cpu'))->get();
        $cpuCount = 0;
        foreach ($cpus as $cpu) {
            $upper = strtoupper($cpu->name);
            $chipset = null;

            // AMD Ryzen
            if (preg_match('/RYZEN\s*5\s*9[56]00X?/', $upper)) {
                $chipset = 'Ryzen 5 9600X';
            } elseif (preg_match('/RYZEN\s*5\s*7[56]00X3D/', $upper)) {
                $chipset = 'Ryzen 5 7600X3D';
            } elseif (preg_match('/RYZEN\s*5\s*7600X\b/', $upper)) {
                $chipset = 'Ryzen 5 7600X';
            } elseif (preg_match('/RYZEN\s*5\s*7600\b/', $upper)) {
                $chipset = 'Ryzen 5 7600';
            } elseif (preg_match('/RYZEN\s*7\s*9[87]00X3D/', $upper)) {
                $chipset = 'Ryzen 7 9800X3D';
            } elseif (preg_match('/RYZEN\s*7\s*9700X/', $upper)) {
                $chipset = 'Ryzen 7 9700X';
            } elseif (preg_match('/RYZEN\s*7\s*7800X3D/', $upper)) {
                $chipset = 'Ryzen 7 7800X3D';
            } elseif (preg_match('/RYZEN\s*7\s*7700X/', $upper)) {
                $chipset = 'Ryzen 7 7700X';
            } elseif (preg_match('/RYZEN\s*7\s*7700\b/', $upper)) {
                $chipset = 'Ryzen 7 7700';
            } elseif (preg_match('/RYZEN\s*9\s*9900X/', $upper)) {
                $chipset = 'Ryzen 9 9900X';
            } elseif (preg_match('/RYZEN\s*9\s*9950X/', $upper)) {
                $chipset = 'Ryzen 9 9950X';
            } elseif (preg_match('/RYZEN\s*9\s*7900X/', $upper)) {
                $chipset = 'Ryzen 9 7900X';
            } elseif (preg_match('/RYZEN\s*9\s*7950X/', $upper)) {
                $chipset = 'Ryzen 9 7950X';
            }
            // Intel Core Ultra (Arrow Lake)
            elseif (preg_match('/CORE\s*ULTRA\s*9\s*285K/', $upper)) {
                $chipset = 'Core Ultra 9 285K';
            } elseif (preg_match('/CORE\s*ULTRA\s*7\s*265K/', $upper)) {
                $chipset = 'Core Ultra 7 265K';
            } elseif (preg_match('/CORE\s*ULTRA\s*5\s*245K/', $upper)) {
                $chipset = 'Core Ultra 5 245K';
            }
            // Intel Core 14th/13th gen
            elseif (preg_match('/CORE\s*I9-14900K/', $upper)) {
                $chipset = 'Core i9-14900K';
            } elseif (preg_match('/CORE\s*I7-14700K/', $upper)) {
                $chipset = 'Core i7-14700K';
            } elseif (preg_match('/CORE\s*I5-14600K/', $upper)) {
                $chipset = 'Core i5-14600K';
            } elseif (preg_match('/CORE\s*I9-13900K/', $upper)) {
                $chipset = 'Core i9-13900K';
            } elseif (preg_match('/CORE\s*I7-13700K/', $upper)) {
                $chipset = 'Core i7-13700K';
            } elseif (preg_match('/CORE\s*I5-13600K/', $upper)) {
                $chipset = 'Core i5-13600K';
            } elseif (preg_match('/CORE\s*I5-12400/', $upper)) {
                $chipset = 'Core i5-12400';
            } elseif (preg_match('/CORE\s*I5-12600K/', $upper)) {
                $chipset = 'Core i5-12600K';
            }

            if ($chipset && ! $cpu->chipset) {
                $cpu->chipset = $chipset;
                $cpu->save();
                $cpuCount++;
            }
        }

        // Coolers: attach supported_sockets to current-gen retail units so the
        // cooler_mount compatibility rule has real data. Matching is conservative:
        // only well-known modern air/AIO families that genuinely support
        // AM4/AM5 + Intel LGA1700/LGA1851 get a socket list; legacy or exotic
        // coolers stay unannotated and the rule passes vacuously.
        $coolerCount = $this->enrichCoolerSockets();

        $this->command?->info("Backfilled {$gpuCount} GPU chipsets, {$cpuCount} CPU chipsets and enriched {$coolerCount} coolers.");
    }

    /**
     * Add a supported_sockets spec to recognisable current-gen coolers.
     *
     * @return int number of coolers enriched
     */
    protected function enrichCoolerSockets(): int
    {
        $modernSockets = ['AM4', 'AM5', 'LGA1700', 'LGA1851'];

        $patterns = [
            '/\bNH-D1[245]S?\b/',
            '/\bNH-U1[024]S?\b/',
            '/\bNH-U14S\b/',
            '/PEERLESS ASSASSIN/',
            '/\bPA120\b/',
            '/\bFS140\b/',
            '/\bPS120\b/',
            '/\bBA120\b/',
            '/\bAK120\b/',
            '/\bAK400\b/',
            '/\bAK500\b/',
            '/\bAK620\b/',
            '/\bAG400\b/',
            '/\bAG500\b/',
            '/\bAG620\b/',
            '/\bAK700\b/',
            '/LIQUID FREEZER/',
            '/KRAKEN (120|240|280|360)/',
            '/CORSAIR H\d+/',
            '/CORSAIR ICUE H\d+/',
            '/\bH10[05]I\b/',
            '/\bH11[05]I\b/',
            '/\bH15[05]I\b/',
            '/\bH60X?\b/',
            '/\bH100X?\b/',
            '/\bH150X?\b/',
            '/\bSE-224/',
            '/\bSE-207/',
            '/\bSE-226/',
            '/\bSE-234/',
            '/\bSE-914/',
            '/AURAFLOW/',
            '/FROSTFLOW/',
            '/LT720/',
            '/LS720/',
            '/LT520/',
            '/LS520/',
            '/LD240/',
            '/LD360/',
            '/MYSTIQUE (240|360)/',
            '/ASSASSIN III/',
            '/FUMA 3/',
            '/FUMA REV/',
            '/\bD15S?\b/',
            '/\bU12A\b/',
        ];

        $coolers = Component::whereHas('category', fn ($q) => $q->where('slug', 'cooler'))->get();
        $count = 0;

        foreach ($coolers as $cooler) {
            $specs = is_array($cooler->specs) ? $cooler->specs : [];
            $upper = strtoupper($cooler->name);

            $supported = null;
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $upper)) {
                    $supported = $modernSockets;
                    break;
                }
            }

            if ($supported === null) {
                continue;
            }

            $current = $specs['supported_sockets'] ?? [];
            if (! empty($current)) {
                continue;
            }

            $specs['supported_sockets'] = $supported;
            $cooler->specs = $specs;
            $cooler->save();
            $count++;
        }

        return $count;
    }
}
