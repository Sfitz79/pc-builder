<?php

/**
 * TLS CA bundle resolution.
 *
 * WHY THIS FILE EXISTS
 *
 * Two services had a literal Windows path baked into production code:
 *
 *     is_file('C:\Users\simon\cacert.pem')
 *
 * hardcoded in BuilderController::partImage() and again in GeminiService. It was
 * harmless in the deployed copy by luck: `is_file()` returns false on Linux, the
 * `verify` option is simply not applied, and Guzzle falls back to PHP's own CA
 * bundle. Harmless by accident is still a defect - it cannot be configured, it
 * breaks silently if the machine layout changes, and it leaks a developer's
 * directory layout into the repository.
 *
 * WHAT IT DOES INSTEAD
 *
 * Resolves, in order:
 *   1. HTTPS_CA_BUNDLE / SSL_CA_BUNDLE / CURL_CA_BUNDLE from the environment -
 *      the standard conventions, so no app-specific variable is invented.
 *   2. A cacert.pem sitting next to the application, for a developer who
 *      downloaded one rather than fixing their system trust store.
 *   3. The well-known system locations, which is what actually resolves on a
 *      normal Linux host.
 *
 * Returns null when nothing is found, and callers then pass NO `verify` option at
 * all so Guzzle uses its default. That is the important part: a wrong guess must
 * never become `verify => <a path that does not exist>`, which fails every TLS
 * handshake with a confusing error.
 */
return [

    /*
     * Absolute path to a CA bundle, or null to use the system default.
     *
     * `verify_ca_bundle` is evaluated lazily by the helper below, so this stays a
     * plain config value that config:cache can hold.
     */
    'path' => env('HTTPS_CA_BUNDLE')
        ?: env('SSL_CA_BUNDLE')
        ?: env('CURL_CA_BUNDLE'),

    /*
     * Extra places to look, relative to the project root, when the environment
     * says nothing. Deliberately short: a long list of guesses is how a config
     * file ends up looking like a search algorithm.
     */
    'local_candidates' => [
        'cacert.pem',
        'storage/cacert.pem',
        'resources/cacert.pem',
    ],

    /*
     * Home-directory candidates.
     *
     * NOT OPTIONAL, and adding them fixed a regression I introduced. The code this
     * replaced did `is_file('C:\Users\simon\cacert.pem')`, and that file DOES
     * exist on the Boss's machine - so the old line genuinely found a bundle
     * locally. The first version of config/tls.php only searched the project root
     * and system paths, returned NULL here, and silently stopped passing a
     * `verify` option on Windows. tests/tls-proof.php caught it, which is the
     * argument for writing the negative tests before trusting a cleanup.
     *
     * Expressed relative to the home directory so no username is in the repo.
     */
    'home_candidates' => [
        'cacert.pem',
        'cacert.crt',
        '.ssl/cert.pem',
    ],

    /*
     * System locations, checked only when the environment is silent. On Debian and
     * Ubuntu the ca-certificates package installs to this path, which is the single
     * most common cause of "unable to get local issuer certificate" in a
     * hand-rolled container.
     */
    'system_candidates' => [
        '/etc/ssl/certs/ca-certificates.crt',   // Debian, Ubuntu
        '/etc/pki/tls/certs/ca-bundle.crt',      // RHEL, Fedora, Amazon Linux
        '/etc/ssl/ca-bundle.pem',                // Alpine, older RHEL
        '/etc/pki/tls/cacert.pem',
        '/usr/local/share/certs/ca-root-nss.crt', // FreeBSD
        '/etc/ssl/cert.pem',                     // macOS, Alpine legacy
    ],

];