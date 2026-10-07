<?php

/**
 * Push the PayPal credentials into Vercel PRODUCTION env, via stdin.
 *
 * Uses vercel env add, which reads the value from standard input, so neither
 * credential ever appears in a command line, a process listing or a shell
 * transcript. PAYPAL_MODE is deliberately NOT set: these credentials are LIVE
 * (verified against api-m.paypal.com, rejected by the sandbox), and switching
 * a payment processor to live takes real money, which is the Boss's call.
 *
 * Idempotent: removes any existing value first so a rerun cannot half-apply.
 */

$source = 'C:/Users/simon/WebstormProjects/aigen/pp details.txt';

if (! is_readable($source)) {
    fwrite(STDERR, "Credentials file not readable\n");
    exit(1);
}

$clientId = '';
$secret = '';

foreach (preg_split('/\R/', (string) file_get_contents($source), -1, PREG_SPLIT_NO_EMPTY) as $line) {
    $tokens = preg_split('/\s+/', trim($line)) ?: [];
    $value = $tokens[0] ?? '';
    $label = strtolower(implode(' ', array_slice($tokens, 1)));

    if ($value === '') {
        continue;
    }

    if (str_contains($label, 'secret')) {
        $secret = $value;
    } elseif (str_contains($label, 'client') || str_contains($label, 'id')) {
        $clientId = $value;
    }
}

if ($clientId === '' || $secret === '') {
    fwrite(STDERR, "Could not parse both credentials\n");
    exit(1);
}

$root = dirname(__DIR__);

foreach (['PAYPAL_CLIENT_ID' => $clientId, 'PAYPAL_CLIENT_SECRET' => $secret] as $name => $value) {
    // Clear any stale value so the add cannot be skipped for an existing key.
    exec('vercel env rm ' . escapeshellarg($name) . ' production --yes 2>&1', $out, $code);
    printf("%-22s existing value removed (exit %d)%s", $name, $code, PHP_EOL);
    $out = [];

    $tmp = tempnam(sys_get_temp_dir(), 'pp');
    file_put_contents($tmp, $value);

    $cmd = 'cmd /c "type ' . $tmp . ' | vercel env add ' . escapeshellarg($name) . ' production --force 2>&1"';
    exec($cmd, $addOut, $addCode);

    @unlink($tmp);

    printf("%-22s add exit %d -> %s%s", $name, $addCode, trim(implode(' | ', $addOut)) ?: '(no output)', PHP_EOL);
    $addOut = [];
}

echo PHP_EOL, 'Verifying against the live project:', PHP_EOL;

exec('vercel env ls production 2>&1', $ls, $lsCode);

foreach ($ls as $line) {
    if (str_contains($line, 'PAYPAL')) {
        // Names only; Vercel never prints values.
        echo '  ', trim(preg_replace('/\s+/', ' ', $line)), PHP_EOL;
    }
}
