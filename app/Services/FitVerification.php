<?php

namespace App\Services;

/**
 * Fail-closed physical fit verification.
 *
 * THE DEFECT THIS FIXES
 * ---------------------
 * CompatibilityCheckerService checked fit like this:
 *
 *     $maxGpuLength = $parts['case']['max_gpu_length'] ?? null;
 *     $gpuLength    = $parts['gpu']['length'] ?? null;
 *     if ($maxGpuLength && $gpuLength && $gpuLength > $maxGpuLength) { ... }
 *
 * The comparison only runs when BOTH values are truthy, so an unknown dimension
 * means the check silently does not happen - and `compatible` is then computed
 * as `empty($errors)`, which is true. The build is reported compatible because
 * nothing objected.
 *
 * Measured on PRODUCTION Neon (2026-10-05, 2708 components, all active):
 *
 *     gpu.length                    306 of 306 missing (100%)
 *     case.max_gpu_length           399 of 399 missing (100%)
 *     case.max_cpu_cooler_height    399 of 399 missing (100%)
 *     cooler.height                 372 of 372 missing (100%)
 *     case.form_factor              399 of 399 missing (100%)
 *
 * So THREE fit checks were dead in production: GPU length, cooler height, and
 * case-vs-motherboard form factor. Every build was being told it fits.
 *
 * A 5080-class card is around 360mm and a 4090 around 336mm, so this is not a
 * cosmetic gap. Telling a customer a card fits a case it does not fit is a
 * returned build and a broken 5-star rating.
 *
 * WHAT "FAIL CLOSED" MEANS HERE
 * ----------------------------
 * Blocking every build would be fail-closed but useless: with 100% of dimensions
 * missing, a hard block makes the builder unusable and costs every sale. The
 * honest resolution is that a build is never reported as VERIFIED unless the
 * check actually ran.
 *
 * Each check returns one of:
 *   pass        ran, and the parts fit
 *   fail        ran, and they do not fit  -> blocked
 *   unverified  a dimension was missing, so nothing was actually proven
 *
 * `verified` is true only when no check is unverified. The UI shows "clearance
 * not verified" instead of implying a pass. That is the same truthfulness the
 * rest of this project insists on: never present an unchecked result as a
 * checked one.
 *
 * Note this deliberately does NOT invent a value. A guess that a card fits is
 * worse than an honest "unverified", because the guess is invisible.
 */
class FitVerification
{
    public const PASS = 'pass';
    public const FAIL = 'fail';
    public const UNVERIFIED = 'unverified';

    /**
     * @param  array<string,array<string,mixed>>  $parts  keyed by category slug
     * @return array{
     *     verified:bool, blocked:bool, checks:array<int,array<string,mixed>>,
     *     warnings:array<int,string>
     * }
     */
    public static function assess(array $parts): array
    {
        $checks = [];
        $warnings = [];

        $case = is_array($parts['case'] ?? null) ? $parts['case'] : [];
        $gpu = is_array($parts['gpu'] ?? null) ? $parts['gpu'] : [];
        $cooler = is_array($parts['cooler'] ?? null) ? $parts['cooler'] : [];
        $board = is_array($parts['motherboard'] ?? null) ? $parts['motherboard'] : [];

        $mm = static function (array $part, string ...$keys): ?float {
            foreach ($keys as $k) {
                $v = $part[$k] ?? null;
                if ($v !== null && $v !== '' && is_numeric($v)) {
                    return (float) $v;
                }
            }
            return null;
        };

        // ---- 1. GPU length against case clearance -------------------------
        $checks[] = self::compare(
            'gpu-length',
            'GPU length vs case clearance',
            $mm($gpu, 'length', 'length_mm'),
            $mm($case, 'max_gpu_length', 'max_gpu_length_mm'),
            'mm',
            'gpu'
        );

        // ---- 2. CPU cooler height against panel clearance ----------------
        $checks[] = self::compare(
            'cooler-height',
            'CPU cooler height vs case clearance',
            $mm($cooler, 'height', 'height_mm'),
            $mm($case, 'max_cpu_cooler_height', 'max_cooler_height', 'max_cpu_cooler_height_mm'),
            'mm',
            'cooler'
        );

        // ---- 3. Motherboard form factor against case support --------------
        // A form factor is a name, not a number, so it is handled separately. It
        // matters as much as the millimetres: an ATX board does not go in an
        // mATX case whatever the dimensions say.
        $mbForm = self::str($board, 'form_factor', 'formfactor');
        $caseForm = self::str($case, 'form_factor', 'formfactor');
        $supported = self::strList($case, 'supported_form_factors', 'supported_form_factors');

        if ($mbForm === null || ($caseForm === null && $supported === [])) {
            $checks[] = self::unverified(
                'form-factor',
                'Motherboard form factor vs case support',
                $mbForm === null
                    ? 'motherboard form factor is not recorded, so case support cannot be confirmed'
                    : 'case supported form factors are not recorded, so the board cannot be confirmed'
            );
        } elseif ($supported !== []) {
            $ok = in_array($mbForm, $supported, true);
            $checks[] = [
                'id' => 'form-factor',
                'label' => 'Motherboard form factor vs case support',
                'state' => $ok ? self::PASS : self::FAIL,
                'detail' => $ok
                    ? "{$mbForm} is supported by this case"
                    : "{$mbForm} is not supported by this case (supports: " . implode(', ', $supported) . ')',
            ];
        } else {
            // Both known and the case declares exactly one form factor.
            $ok = strcasecmp($mbForm, $caseForm) === 0;
            $checks[] = [
                'id' => 'form-factor',
                'label' => 'Motherboard form factor vs case support',
                'state' => $ok ? self::PASS : self::FAIL,
                'detail' => $ok
                    ? "both are {$mbForm}"
                    : "motherboard is {$mbForm} but the case is {$caseForm}",
            ];
        }

        // ---- roll up -----------------------------------------------------
        $blocked = false;
        $unverified = [];
        foreach ($checks as $c) {
            if ($c['state'] === self::FAIL) {
                $blocked = true;
            } elseif ($c['state'] === self::UNVERIFIED) {
                $unverified[] = $c;
                $warnings[] = 'Not verified: ' . $c['detail'];
            }
        }

        return [
            'verified' => $unverified === [],
            'blocked' => $blocked,
            'checks' => $checks,
            'warnings' => $warnings,
        ];
    }

    /** Compare one measured part against one limit. */
    private static function compare(
        string $id,
        string $label,
        ?float $actual,
        ?float $limit,
        string $unit,
        string $what
    ): array {
        if ($actual === null) {
            return self::unverified(
                $id,
                $label,
                "{$what} size is not recorded, so the {$id} check could not run"
            );
        }
        if ($limit === null) {
            return self::unverified(
                $id,
                $label,
                "the case does not record its maximum {$what} size, so there is nothing to compare against"
            );
        }
        $ok = $actual <= $limit;
        return [
            'id' => $id,
            'label' => $label,
            'state' => $ok ? self::PASS : self::FAIL,
            'detail' => $ok
                ? sprintf('%g%s fits within %g%s', $actual, $unit, $limit, $unit)
                : sprintf('%g%s EXCEEDS the %g%s limit', $actual, $unit, $limit, $unit),
        ];
    }

    private static function unverified(string $id, string $label, string $detail): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'state' => self::UNVERIFIED,
            'detail' => $detail,
        ];
    }

    private static function str(array $part, string ...$keys): ?string
    {
        foreach ($keys as $k) {
            $v = $part[$k] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return strtolower(trim($v));
            }
        }
        return null;
    }

    /** @return array<int,string> */
    private static function strList(array $part, string ...$keys): array
    {
        foreach ($keys as $k) {
            $v = $part[$k] ?? null;
            if (is_array($v)) {
                $out = [];
                foreach ($v as $item) {
                    if (is_string($item) && trim($item) !== '') {
                        $out[] = strtolower(trim($item));
                    }
                }
                if ($out !== []) {
                    return $out;
                }
            }
            if (is_string($v) && str_contains($v, ',')) {
                $out = array_values(array_filter(array_map(
                    static fn ($s) => strtolower(trim($s)),
                    explode(',', $v)
                )));
                if ($out !== []) {
                    return $out;
                }
            }
        }
        return [];
    }
}
