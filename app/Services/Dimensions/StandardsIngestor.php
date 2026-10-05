<?php

namespace App\Services\Dimensions;

use PDO;

/**
 * Applies PUBLISHED-STANDARD dimensions to components, with provenance.
 *
 * WHY A SUBSET AND NOT EVERYTHING
 * ------------------------------
 * Six of the eight categories have a fit-critical number fixed by a published
 * standard, so those can be written accurately without scraping anybody:
 *
 *   motherboard   a board's form factor IS its size (ATX 305x244, mATX 244x244,
 *                 Mini-ITX 170x170, E-ATX 305x330). Derivable, not guessable.
 *   psu           ATX envelope 150x86x140, SFX 125x63.5x100. Length is per-model.
 *   ram           DIMM 133.35mm long, SO-DIMM 69.6mm. Heatspreader height is
 *                 per-model and is the number that causes cooler overhang.
 *   storage       M.2 2280 = 22x80, 2.5in = 100x70x7, 3.5in = 147x101.6x26.1.
 *
 * The remaining three are deliberately NOT filled in:
 *
 *   gpu           length / slot width / height differ per SKU
 *   cooler        height differs per model
 *   case          max_gpu_length and max_cpu_cooler_height differ per model
 *
 * No standard fixes those, and they are exactly the numbers that decide whether a
 * build physically goes together. They must come from the manufacturer's own
 * sheet for that model. Writing a plausible-looking number here would be worse
 * than leaving it null, because FitVerification would then report a PASS on a
 * guess - turning an honest "unverified" into a false assurance. That is the one
 * outcome this whole exercise exists to prevent.
 *
 * DRY RUN BY DEFAULT. Nothing is written unless --apply is passed, and the dry
 * run prints exactly what would change.
 */
class StandardsIngestor
{
    public function __construct(
        private readonly PDO $db,
        private readonly array $standards
    ) {
    }

    public static function loadStandards(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException("standards file not found: {$path}");
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('standards.json is not valid JSON');
        }
        return $decoded;
    }

    /**
     * Plan every change without writing anything.
     *
     * @return array{changes:array<int,array<string,mixed>>,skipped:array<int,array<string,mixed>>}
     */
    public function plan(): array
    {
        $changes = [];
        $skipped = [];

        foreach ($this->activeComponents() as $row) {
            $cat = (string) $row['category'];
            $id = (int) $row['id'];
            $name = (string) $row['name'];
            $specs = $this->decodeSpecs($row['specs']);

            $before = $specs;
            $source = null;

            switch ($cat) {
                case 'motherboard':
                    $source = $this->applyMotherboard($specs, $name);
                    break;
                case 'psu':
                    $source = $this->applyPsu($specs, $name);
                    break;
                case 'ram':
                    $source = $this->applyRam($specs, $name);
                    break;
                case 'storage':
                    $source = $this->applyStorage($specs, $name);
                    break;
                default:
                    // gpu / cooler / case need per-model manufacturer data.
                    $skipped[] = [
                        'id' => $id,
                        'category' => $cat,
                        'name' => $name,
                        'why' => 'per-model value with no published standard; must be sourced from the manufacturer sheet',
                    ];
                    continue 2;
            }

            if ($source === null) {
                $skipped[] = [
                    'id' => $id,
                    'category' => $cat,
                    'name' => $name,
                    'why' => 'could not determine the form factor or module type from the recorded specs or the name',
                ];
                continue;
            }

            if ($specs === $before) {
                $skipped[] = [
                    'id' => $id,
                    'category' => $cat,
                    'name' => $name,
                    'why' => 'already populated, nothing to change',
                ];
                continue;
            }

            $changes[] = [
                'id' => $id,
                'category' => $cat,
                'name' => $name,
                'added' => $this->diffKeys($before, $specs),
                'specs' => $specs,
                'source' => $source,
            ];
        }

        return ['changes' => $changes, 'skipped' => $skipped];
    }

    /** Write the planned changes. Only ever called after a reviewed dry run. */
    public function apply(array $changes): int
    {
        $stmt = $this->db->prepare('UPDATE components SET specs = :specs, updated_at = NOW() WHERE id = :id');
        $done = 0;
        foreach ($changes as $change) {
            $stmt->execute([
                'specs' => json_encode($change['specs'], JSON_UNESCAPED_SLASHES),
                'id' => $change['id'],
            ]);
            $done++;
        }
        return $done;
    }

    // ------------------------------------------------------------------

    private function applyMotherboard(array &$specs, string $name): ?string
    {
        $forms = $this->standards['motherboard_forms'];
        $form = $this->slugify((string) ($specs['form_factor'] ?? ''));
        if ($form === '' || !isset($forms[$form])) {
            // Fall back to the product name, which usually states the form factor.
            $hay = strtolower($name);
            foreach (['e-atx' => 'e-atx', 'micro-atx' => 'm-atx', 'matx' => 'm-atx',
                      'mini-itx' => 'itx', 'mitx' => 'itx', 'itx' => 'itx'] as $needle => $key) {
                if (str_contains($hay, $needle)) {
                    $form = $key;
                    break;
                }
            }
        }
        if ($form === '' || !isset($forms[$form])) {
            return null;
        }
        $def = $forms[$form];
        $specs['form_factor'] = $def['label'];
        $specs['width'] = $def['width'];
        $specs['depth'] = $def['depth'];
        $specs['dimension_source'] = $def['label'] . ' form factor is fixed by published specification';
        return $forms['_source'] ?? 'published form factor specification';
    }

    private function applyPsu(array &$specs, string $name): ?string
    {
        $envs = $this->standards['psu_envelopes'];
        $type = $this->slugify((string) ($specs['form_factor'] ?? $specs['type'] ?? ''));
        if ($type === '' || !isset($envs[$type])) {
            // Positive tokens only. "Corsair RM750x" carries no form factor at all,
            // and assuming ATX because it is the common case would assert an
            // envelope the manufacturer never published for that unit.
            $hay = strtolower($name);
            if (str_contains($hay, 'sfx-l') || str_contains($hay, 'sfxl')) {
                $type = 'sxl';
            } elseif (str_contains($hay, 'sfx')) {
                $type = 'sfx';
            } elseif (str_contains($hay, 'atx12vo') || str_contains($hay, '12vo')) {
                $type = 'atx12vo';
            } elseif (str_contains($hay, 'atx')) {
                $type = 'atx';
            }
        }
        if ($type === '' || !isset($envs[$type])) {
            return null;
        }
        $def = $envs[$type];
        $specs['form_factor'] = $def['label'];
        $specs['width'] = $def['width'];
        $specs['height'] = $def['height'];
        $specs['depth'] = $def['depth'];
        // PSU length is deliberately left unset: it is per-model and it is the
        // number that decides whether the unit physically fits the bay.
        $specs['dimension_source'] = $def['source'] . ' - envelope only, length is per-model';
        return $def['source'];
    }

    private function applyRam(array &$specs, string $name): ?string
    {
        $mods = $this->standards['ram_modules'];
        $type = $this->slugify((string) ($specs['type'] ?? $specs['module_type'] ?? ''));
        if ($type === '' || !isset($mods[$type])) {
            $hay = strtolower($name);
            if (str_contains($hay, 'sodimm') || str_contains($hay, 'so-dimm') || str_contains($hay, 'laptop')) {
                $type = 'sodimm';
            } elseif (str_contains($hay, 'ecc') && str_contains($hay, 'udimm')) {
                $type = 'ecc-dimm';
            } elseif (str_contains($hay, 'udimm') || str_contains($hay, 'ddr5') || str_contains($hay, 'ddr4') || str_contains($hay, 'ddr3')) {
                $type = 'dimm';
            }
        }
        if ($type === '' || !isset($mods[$type])) {
            return null;
        }
        $def = $mods[$type];
        $specs['type'] = $def['label'];
        $specs['length'] = $def['length'];
        $specs['pcb_height'] = $def['pcb_height'];
        // heatspreader_height stays unset: it is per-kit and decides cooler overhang.
        $specs['dimension_source'] = 'JEDEC module dimensions - heatspreader height is per-kit';
        return $mods['_source'] ?? 'JEDEC module specification';
    }

    private function applyStorage(array &$specs, string $name): ?string
    {
        $dev = $this->standards['storage_devices'];
        $hay = strtolower($name);
        $existing = $this->slugify((string) ($specs['form_factor'] ?? ''));
        $form = '';

        // POSITIVE EVIDENCE ONLY.
        //
        // A first pass defaulted anything not mentioning M.2 to 2.5 inch, and would
        // have stamped 29 parts on that basis. That is a guess dressed as a
        // standard: an M.2 stick called "WD Blue SN580" contains no "M.2" at all.
        // Absence of a token is not evidence of the opposite form factor, so every
        // branch below has to positively identify the device.
        if ($existing !== '' && isset($dev[$existing])) {
            $form = $existing;
        } elseif (preg_match('/m\.?2[^0-9]{0,6}(\d{3,5})/', $hay, $m)) {
            // Only honour an M.2 length that is actually written down, e.g. "M.2 2280".
            $candidate = 'm2-' . $m[1];
            $form = isset($dev[$candidate]) ? $candidate : '';
        } elseif (str_contains($hay, 'm.2') || str_contains($hay, 'nvme') || str_contains($hay, 'm2')) {
            // M.2 confirmed but length not stated. 2280 is the common case, but
            // writing it anyway would assert a length nobody published, so it is
            // left null and reported as skipped.
            $form = '';
        } elseif (str_contains($hay, '2.5') || str_contains($hay, '2,5')) {
            $form = '2.5-ssd';
        } elseif (str_contains($hay, '3.5') || str_contains($hay, '3,5')) {
            $form = '3.5-hdd';
        }

        if ($form === '' || !isset($dev[$form])) {
            return null;
        }
        $def = $dev[$form];
        $specs['form_factor'] = $def['label'];
        $specs['length'] = $def['length'];
        $specs['width'] = $def['width'];
        if (isset($def['height'])) {
            $specs['height'] = $def['height'];
        }
        $specs['dimension_source'] = 'device form factor is fixed by published specification';
        return $dev['_source'] ?? 'published device form factor specification';
    }

    // ------------------------------------------------------------------

    private function activeComponents(): array
    {
        // `active = true`, not `active = 1`: on Postgres this column is a boolean
        // and `= 1` throws "operator does not exist: boolean = integer" while
        // working perfectly in SQLite. Project rule 7, hit live.
        $sql = 'SELECT comp.id, comp.name, comp.specs, c.slug AS category
                  FROM components comp JOIN categories c ON c.id = comp.category_id
                 WHERE comp.active = true';
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    private function decodeSpecs(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }

    private function diffKeys(array $before, array $after): array
    {
        $out = [];
        foreach ($after as $k => $v) {
            if (!array_key_exists($k, $before) || $before[$k] !== $v) {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private function slugify(string $s): string
    {
        $s = strtolower(trim($s));
        $s = str_replace(['micro atx', 'micro-atx', 'mini itx', 'mini-itx', 'e-atx', 'sodimm', 'so-dimm', 'form factor', 'formfactor'], ['m-atx', 'm-atx', 'itx', 'itx', 'e-atx', 'sodimm', 'sodimm', '', ''], $s);
        $s = str_replace('matx', 'm-atx', $s);
        return trim(preg_replace('/[^a-z0-9\-\.\+ ]/', '', $s), '- ');
    }
}
