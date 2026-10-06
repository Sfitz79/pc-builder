<?php
// Rule 4 for a REQUEST: read the live OpenAPI schema rather than guessing the
// body. A {"detail": ...} payload is FastAPI's 422 validation response.
$ch = curl_init('http://127.0.0.1:8191/openapi.json');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
$raw = curl_exec($ch);
curl_close($ch);
$j = json_decode((string) $raw, true);
if (!is_array($j)) {
    echo "could not read openapi.json\n";
    exit(1);
}
echo "title: " . ($j['info']['title'] ?? '?') . ' v' . ($j['info']['version'] ?? '?') . "\n\n";
foreach (($j['paths'] ?? []) as $path => $ops) {
    foreach ($ops as $method => $op) {
        $ref = $op['requestBody']['content']['application/json']['schema']['$ref'] ?? null;
        $params = array_map(fn ($p) => ($p['name'] ?? '?') . ($p['required'] ?? false ? '*' : ''), $op['parameters'] ?? []);
        printf("%-6s %-24s body=%s params=[%s]\n", strtoupper($method), $path, $ref ?? '-', implode(',', $params));
    }
}
echo "\n-- component schemas --\n";
foreach (($j['components']['schemas'] ?? []) as $name => $schema) {
    $req = $schema['required'] ?? [];
    $props = array_keys($schema['properties'] ?? []);
    printf("\n%s\n  required: %s\n  props: %s\n", $name, implode(', ', $req) ?: '-', implode(', ', $props) ?: '-');
    foreach (($schema['properties'] ?? []) as $pn => $pv) {
        if (!empty($pv['enum'])) {
            printf("    %s enum: %s\n", $pn, implode('|', array_map(fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : (string) $v, array_slice($pv['enum'], 0, 14))));
        }
    }
}
