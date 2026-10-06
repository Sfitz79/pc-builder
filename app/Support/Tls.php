<?php

namespace App\Support;

/**
 * Resolve a CA bundle path, or null to let Guzzle use its own default.
 *
 * This replaces a literal `C:\Users\simon\cacert.pem` that had been baked into
 * two services. See config/tls.php for why the original was wrong and what the
 * resolution order is.
 *
 * The important behaviour is the null case. Callers must pass NO `verify` option
 * when this returns null, rather than passing a path that does not exist - a
 * missing bundle is a problem, but a wrong path turns a working TLS handshake
 * into "unable to get local issuer certificate" for every outbound call, which
 * is the exact error this was added to fix.
 */
final class Tls
{
    /**
     * Memoised per process. Resolving walks the filesystem and this is called on
     * outbound requests, several times per render.
     *
     * @var string|null|false false = not yet resolved, so null is a real answer
     */
    private static $resolved = false;

    /**
     * Absolute path to a usable CA bundle, or null for the system default.
     */
    public static function bundlePath(): ?string
    {
        if (self::$resolved !== false) {
            return self::$resolved;
        }

        self::$resolved = self::resolve();

        return self::$resolved;
    }

    /**
     * Guzzle options for an outbound request. Returns [] when the system default
     * should be used, which is the common case and must stay cheap.
     *
     * @return array<string, mixed>
     */
    public static function guzzleOptions(): array
    {
        $path = self::bundlePath();

        return $path === null ? [] : ['verify' => $path];
    }

    /**
     * Force the next call to resolve again. Tests only - the memo is otherwise
     * correct for the life of a request.
     */
    public static function reset(): void
    {
        self::$resolved = false;
    }

    private static function resolve(): ?string
    {
        // 1. The environment. HTTPS_CA_BUNDLE first because it is the one Guzzle
        //    and most tooling already understand.
        $configured = config('tls.path');
        if (is_string($configured) && $configured !== '' && is_file($configured)) {
            return $configured;
        }

        // 2. Next to the application, for a developer who downloaded a bundle
        //    rather than fixing their system trust store.
        $root = base_path();
        foreach ((array) config('tls.local_candidates', []) as $relative) {
            $candidate = $root . DIRECTORY_SEPARATOR . ltrim((string) $relative, '/\\');
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        // 3. The home directory. This is where the bundle the previous hardcoded
        //    line actually lived, so leaving it out changes behaviour on Windows -
        //    see config/tls.php for how that regression was found.
        $home = self::homeDirectory();
        if ($home !== null) {
            foreach ((array) config('tls.home_candidates', []) as $relative) {
                $candidate = $home . DIRECTORY_SEPARATOR . ltrim((string) $relative, '/\\');
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        // 4. System locations. This is the branch that resolves on a normal Linux
        //    host and on Vercel, which is why the deployed copy never needed the
        //    hardcoded path it used to carry.
        foreach ((array) config('tls.system_candidates', []) as $candidate) {
            if ($candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The user's home directory, or null when it cannot be determined.
     *
     * Deliberately does not assume a username. USERPROFILE on Windows, HOME
     * everywhere else, and home() as the last resort - so no personal directory
     * is written into the repository, which is the whole point of this refactor.
     */
    private static function homeDirectory(): ?string
    {
        foreach (['USERPROFILE', 'HOME'] as $key) {
            $value = getenv($key);
            if (is_string($value) && $value !== '' && is_dir($value)) {
                return rtrim($value, '/\\');
            }
        }

        try {
            // Laravel's helper, which resolves from the same environment.
            $home = base_path('..');
            return is_dir($home) ? $home : null;
        } catch (\Throwable) {
            return null;
        }
    }
}