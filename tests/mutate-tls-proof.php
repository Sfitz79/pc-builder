<?php
/**
 * Mutation harness for tests/tls-proof.php.
 *
 * This proof exists because a cleanup broke the thing it was cleaning: the first
 * version of config/tls.php dropped the home-directory candidate, the resolver
 * returned NULL on the Boss's machine, and TLS verification silently stopped being
 * applied. The proof caught it - but only because the negative cases were written.
 *
 * A proof that has never been shown to fail is a comment. Each mutation below
 * reintroduces one specific failure and asserts the proof goes red.
 *
 * Run: php tests/mutate-tls-proof.php
 */

$root = dirname(__DIR__);
$files = [
    'tls' => $root . '/app/Support/Tls.php',
    'cfg' => $root . '/config/tls.php',
];
$backups = [];
foreach ($files as $k => $p) {
    $backups[$k] = file_get_contents($p);
}
$restore = function () use ($files, $backups) {
    foreach ($files as $k => $p) {
        file_put_contents($p, $backups[$k]);
    }
};

$mutations = [
    [
        'name' => 'the home-directory candidate is dropped (THE regression: local TLS silently unverified)',
        'file' => 'tls',
        'find' => "foreach ((array) config('tls.home_candidates', []) as \$relative) {",
        'replace' => "foreach ([] as \$relative) {",
    ],
    [
        /*
         * Removing the existence check on the candidate lists. The first version
         * of this mutation replaced `return $candidate;` with itself plus a
         * comment, which is not a mutation at all - it changed no behaviour and
         * then reported MISSED, which would have read as "the proof is weak" when
         * the truth was "the mutation was empty". A no-op mutation is worse than
         * no mutation: it manufactures a false conclusion.
         */
        'name' => 'a resolved candidate is returned without checking it exists',
        'file' => 'tls',
        'find' => "if (is_file(\$candidate)) {\n                    return \$candidate;\n                }",
        'replace' => 'return $candidate;',
        'all' => true,
    ],
    [
        'name' => 'guzzleOptions returns an empty verify path instead of no option',
        'file' => 'tls',
        'find' => "return \$path === null ? [] : ['verify' => \$path];",
        'replace' => "return ['verify' => \$path ?? ''];",
    ],
    [
        'name' => 'a configured path is trusted without an is_file check',
        'file' => 'tls',
        'find' => "if (is_string(\$configured) && \$configured !== '' && is_file(\$configured)) {",
        'replace' => "if (is_string(\$configured) && \$configured !== '') {",
    ],
    [
        /*
         * NOT A BREAK, and keeping it would be dishonest. This proof is a
         * CORRECTNESS proof: it asserts the resolved value is stable and that a
         * missing bundle degrades to the system default. Removing memoisation
         * changes neither - resolution is deterministic, so every call returns the
         * same answer. It only costs extra filesystem walks per outbound request,
         * which is a performance property this proof does not measure.
         *
         * An earlier version of this harness scored it MISSED and that would have
         * been the harness inventing a requirement the code never had.
         */
        'name' => 'REMOVED - memoisation is a performance property, not a correctness one',
        'skip' => true,
    ],
];

function runProof(string $root): array {
    $output = [];
    $code = 0;
    // PHP_BINARY, not the bare name 'php'. On Windows the spawned shell does not
    // inherit the WinGet shim directory, so exec('php ...') failed with
    // "'php' is not recognized" and the harness reported a red baseline for a
    // green proof - the least useful possible failure mode.
    $php = escapeshellarg(PHP_BINARY);
    exec($php . ' ' . escapeshellarg($root . '/tests/tls-proof.php') . ' 2>&1', $output, $code);
    return ['pass' => $code === 0, 'out' => implode("\n", $output)];
}

$failed = 0;

$base = runProof($root);
if (! $base['pass']) {
    fwrite(STDERR, "BASELINE IS RED - fix it before mutation testing.\n" . $base['out'] . "\n");
    exit(1);
}
echo "baseline: TLS proof passes\n\n";

foreach ($mutations as $m) {
    // A `skip` entry documents a mutation that was REMOVED because it turned out
    // not to break anything. It has no file, so it must not reach the file
    // handling below - doing so read an empty path and threw.
    if ($m['skip'] ?? false) {
        echo "  - n/a      {$m['name']}\n";
        continue;
    }

    $path = $files[$m['file']];
    $src = file_get_contents($path);
    $needle = $m['find'];

    if (substr_count($src, $needle) === 0) {
        echo "  ? SKIP     {$m['name']}\n             pattern no longer present\n";
        $failed++;
        continue;
    }

    // 'first' targets the FIRST occurrence, which is the one inside the is_file()
    // guard for the return-value mutation.
    $mutated = ($m['all'] ?? false)
        ? str_replace($needle, $m['replace'], $src)
        : preg_replace('/' . preg_quote($needle, '/') . '/', addcslashes($m['replace'], '\\$'), $src, 1);

    file_put_contents($path, $mutated);
    $res = runProof($root);
    $restore();

    if ($res['pass']) {
        echo "  x MISSED   {$m['name']}\n";
        $failed++;
    } else {
        $line = '';
        foreach (explode("\n", $res['out']) as $l) {
            if (str_contains($l, 'FAIL')) { $line = trim($l); break; }
        }
        echo "  v caught   {$m['name']}\n             {$line}\n";
    }
}

$restore();
$applicable = count(array_filter($mutations, fn ($m) => ! ($m['skip'] ?? false)));
echo "\n" . ($applicable - $failed) . '/' . $applicable . " mutations caught.\n";
if ($failed) {
    echo "A proof that cannot go red is not a proof.\n";
    exit(1);
}
echo "tests/tls-proof.php is load-bearing.\n";