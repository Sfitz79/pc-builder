<?php

/**
 * Wire the PayPal credentials supplied in aigen\pp details.txt into pc-builder.
 *
 * Reads the values from the plaintext file the Boss dropped in, writes them to
 * pc-builder's .env, and NEVER echoes either value - only a masked preview and
 * a length, so the secret does not end up in a shell transcript, a log file or
 * this script's own output.
 *
 * Rewrites an existing key in place when present, otherwise appends. Idempotent.
 */

$source = 'C:/Users/simon/WebstormProjects/aigen/pp details.txt';
$target = __DIR__ . '/../.env';

if (! is_readable($source)) {
    fwrite(STDERR, "Credentials file not readable: {$source}\n");
    exit(1);
}

$lines = preg_split('/\R/', (string) file_get_contents($source), -1, PREG_SPLIT_NO_EMPTY);

$clientId = '';
$secret = '';

foreach ($lines as $line) {
    $line = trim($line);

    if ($line === '') {
        continue;
    }

    // The supplied file puts the VALUE FIRST and the label after it, on one
    // line: "<token> pay pal client id" and "<token> secret". Both credentials
    // are single whitespace-free tokens, so the value is the first token and
    // the remainder is the label. Taking the remainder instead - as a
    // label-first parser would - yields nothing here.
    $tokens = preg_split('/\s+/', $line) ?: [];
    $value = $tokens[0];
    $label = strtolower(implode(' ', array_slice($tokens, 1)));

    if ($value === '') {
        continue;
    }

    if (str_contains($label, 'secret')) {
        $secret = $value;
        continue;
    }

    if (str_contains($label, 'client') || str_contains($label, 'id')) {
        $clientId = $value;
    }
}

if ($clientId === '' || $secret === '') {
    fwrite(STDERR, "Could not parse both credentials from the source file.\n");
    exit(1);
}

$mask = static fn (string $v): string => substr($v, 0, 6) . str_repeat('*', max(0, strlen($v) - 10)) . substr($v, -4);

printf("parsed client id : %s (len %d)%s", $mask($clientId), strlen($clientId), PHP_EOL);
printf("parsed secret    : %s (len %d)%s", $mask($secret), strlen($secret), PHP_EOL);

$env = is_readable($target) ? (string) file_get_contents($target) : '';

$set = static function (string $env, string $key, string $value): string {
    $line = $key . '=' . $value;

    if (preg_match('/^' . preg_quote($key, '/') . '=.*$/m', $env) === 1) {
        return (string) preg_replace('/^' . preg_quote($key, '/') . '=.*$/m', $line, $env, 1);
    }

    return rtrim($env, "\r\n") . PHP_EOL . PHP_EOL . '# PayPal REST API credentials (supplied 2026-10-07).' . PHP_EOL . $line . PHP_EOL;
};

$env = $set($env, 'PAYPAL_CLIENT_ID', $clientId);
$env = $set($env, 'PAYPAL_CLIENT_SECRET', $secret);

// Deliberately NOT setting PAYPAL_MODE. It defaults to 'sandbox', which cannot
// take a customer's money. Moving it to 'live' is a real-money decision and is
// the Boss's to make, not the agent's.

if (file_put_contents($target, $env) === false) {
    fwrite(STDERR, "Failed to write {$target}\n");
    exit(1);
}

echo PHP_EOL, 'written to .env:', PHP_EOL;

foreach (['PAYPAL_CLIENT_ID', 'PAYPAL_CLIENT_SECRET'] as $key) {
    preg_match('/^' . $key . '=(.*)$/m', (string) file_get_contents($target), $m);
    printf('  %-22s len %-4d %s%s', $key, strlen($m[1] ?? ''), $mask($m[1] ?? ''), PHP_EOL);
}

if (preg_match('/^PAYPAL_MODE=(.*)$/m', (string) file_get_contents($target), $m) === 1) {
    printf('  %-22s %s%s', 'PAYPAL_MODE', trim($m[1]), PHP_EOL);
} else {
    printf('  %-22s unset -> defaults to sandbox%s', 'PAYPAL_MODE', PHP_EOL);
}
