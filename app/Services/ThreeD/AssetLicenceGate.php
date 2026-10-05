<?php

namespace App\Services\ThreeD;

/**
 * Licence gate for third-party 3D assets.
 *
 * WHY THIS EXISTS
 * ---------------
 * PCTechGuy uses free-to-download community 3D models and texture sets as
 * INTERNAL reference material. Meshes are never served to the public; the only
 * thing a customer ever receives is a rendered image of their own build.
 *
 * That posture is legitimate, but "free to download" is not a licence category,
 * and the platforms mix categories freely on the same search page. A model
 * uploaded to the same bucket as a CC0 one may be CC-BY-NC, CC-BY-ND, editorial
 * only, or under a marketplace licence with a non-commercial clause.
 *
 * Because we SELL the build the render depicts, every asset is treated as
 * COMMERCIAL USE regardless of how the render is described internally.
 *
 * FAIL CLOSED. An asset with an absent, unrecognised or contradictory licence is
 * REJECTED, never defaulted to permitted. An unknown licence is not a free
 * licence, and guessing wrong here is a rights problem on a revenue-bearing
 * storefront rather than a cosmetic bug.
 *
 * What is allowed
 * ---------------
 *   CC0 / Public Domain / CC0-1.0 ....... allowed, no obligation
 *   CC-BY 4.0 / 3.0 ..................... allowed, ATTRIBUTION REQUIRED
 *   CC-BY-SA ............................ rejected (share-alike reaches our output)
 *
 * What is rejected, and why
 * -------------------------
 *   *NC*          non-commercial. We sell the build. Fatal.
 *   *ND*          no derivatives. Repositioning or re-proportioning a mesh is a
 *                 derivative; merging one into a composite does not escape it.
 *   Editorial     cannot be used for commercial or promotional purposes at all.
 *   Personal Use  marketplace personal-use licences forbid commercial use.
 *   Marketplace   e.g. "Royalty Free" - permits use in renders but forbids
 *                 redistribution of source files. Our internal-only posture can
 *                 satisfy this, but it is tracked as REVIEW rather than allowed
 *                 silently, because the terms differ per platform and change.
 *
 * REVIEW assets are not blocked from the reference folder, but they cannot pass
 * the gate, so nothing downstream can treat them as cleared.
 */
class AssetLicenceGate
{
    public const ATTRIBUTION_REQUIRED = 'attribution-required';
    public const ALLOWED = 'allowed';
    public const REJECTED = 'rejected';
    public const REVIEW = 'review-required';

    /**
     * Decide one licence string.
     *
     * @return array{verdict:string, reason:string, attributionRequired:bool}
     */
    public static function evaluate(string $licence, string $source = ''): array
    {
        $raw = strtolower(trim($licence));
        $src = $source !== '' ? ' (' . $source . ')' : '';

        // Absent licence. The single most important case: a community upload with
        // no stated terms is NOT public domain, it is unlicensed.
        if ($raw === '' || $raw === 'unknown' || $raw === 'none' || $raw === 'n/a') {
            return [
                'verdict' => self::REJECTED,
                'reason' => 'no licence stated; an unlicensed upload is not public domain' . $src,
                'attributionRequired' => false,
            ];
        }

        // Non-commercial and no-derivatives are checked FIRST, because a licence
        // such as "CC-BY-NC-ND-4.0" contains CC-BY and would otherwise pass as
        // attribution-only. Both clauses are independently fatal.
        if (str_contains($raw, '-nc') || str_contains($raw, 'noncommercial') || str_contains($raw, 'non-commercial')) {
            return [
                'verdict' => self::REJECTED,
                'reason' => 'non-commercial licence; PCTechGuy sells the build, so the render is commercial use' . $src,
                'attributionRequired' => false,
            ];
        }
        if (str_contains($raw, '-nd') || str_contains($raw, 'noderiv') || str_contains($raw, 'no-derivative')) {
            return [
                'verdict' => self::REJECTED,
                'reason' => 'no-derivatives licence; repositioning or recomposing the mesh is a derivative regardless of attribution' . $src,
                'attributionRequired' => false,
            ];
        }
        if (str_contains($raw, 'editorial')) {
            return [
                'verdict' => self::REJECTED,
                'reason' => 'editorial licence; explicitly forbids commercial or promotional use' . $src,
                'attributionRequired' => false,
            ];
        }
        if (str_contains($raw, 'nc-') === false && str_contains($raw, 'sharealike') === false && str_contains($raw, '-sa')) {
            return [
                'verdict' => self::REJECTED,
                'reason' => 'share-alike licence; would extend over our rendered output' . $src,
                'attributionRequired' => false,
            ];
        }
        if (str_contains($raw, 'personal use') || str_contains($raw, 'personal-use')) {
            return [
                'verdict' => self::REJECTED,
                'reason' => 'personal-use licence; forbids commercial use' . $src,
                'attributionRequired' => false,
            ];
        }
        if (str_contains($raw, 'royalty free') || str_contains($raw, 'royalty-free') || str_contains($raw, 'standard licence')) {
            return [
                'verdict' => self::REVIEW,
                'reason' => 'marketplace licence permitting renders but restricting source redistribution; terms differ per platform and change, so each asset needs a human read of its own terms' . $src,
                'attributionRequired' => false,
            ];
        }

        // Public domain / CC0: nothing owed.
        if (str_contains($raw, 'cc0') || str_contains($raw, 'public domain') || str_contains($raw, 'publicdomain')) {
            return [
                'verdict' => self::ALLOWED,
                'reason' => 'public domain / CC0; no attribution obligation',
                'attributionRequired' => false,
            ];
        }

        // CC-BY: allowed, but only if we actually record who to credit.
        if (str_contains($raw, 'cc-by') || str_contains($raw, 'ccby') || str_contains($raw, 'attribution')) {
            return [
                'verdict' => self::ATTRIBUTION_REQUIRED,
                'reason' => 'CC-BY; commercial use permitted but the creator must be credited, and combining the asset does not remove that duty',
                'attributionRequired' => true,
            ];
        }

        // Anything unrecognised fails closed.
        return [
            'verdict' => self::REJECTED,
            'reason' => 'unrecognised licence string "' . $licence . '"; failing closed rather than assuming permission' . $src,
            'attributionRequired' => false,
        ];
    }

    /**
     * Evaluate a whole manifest.
     *
     * @param  array<int,array<string,mixed>>  $assets
     * @return array{counts:array<string,int>,rows:array<int,array<string,mixed>>,pass:bool}
     */
    public static function evaluateManifest(array $assets): array
    {
        $counts = [self::ALLOWED => 0, self::ATTRIBUTION_REQUIRED => 0, self::REJECTED => 0, self::REVIEW => 0];
        $rows = [];

        foreach ($assets as $asset) {
            $name = (string) ($asset['name'] ?? '(unnamed)');
            $licence = (string) ($asset['licence'] ?? '');
            $source = (string) ($asset['source'] ?? '');
            $result = self::evaluate($licence, $source);

            $attribution = trim((string) ($asset['attribution'] ?? ''));

            // A CC-BY asset with no recorded author is not cleared. The obligation
            // exists whether or not we remembered to write it down, so an empty
            // attribution field rejects rather than warns.
            if ($result['verdict'] === self::ATTRIBUTION_REQUIRED && $attribution === '') {
                $result['verdict'] = self::REJECTED;
                $result['reason'] = 'CC-BY asset with no author recorded; the credit cannot be given, so it is not usable';
            }

            $counts[$result['verdict']]++;
            $rows[] = [
                'name' => $name,
                'source' => $source,
                'licence' => $licence === '' ? '(none)' : $licence,
                'verdict' => $result['verdict'],
                'reason' => $result['reason'],
                'attribution' => $attribution,
            ];
        }

        return [
            'counts' => $counts,
            'rows' => $rows,
            'pass' => $counts[self::REJECTED] === 0,
        ];
    }
}
