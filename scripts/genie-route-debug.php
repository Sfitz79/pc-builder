<?php
// Throwaway diagnostic: what do the routes ACTUALLY look like?
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

$routes = [];
foreach ($app->make('router')->getRoutes() as $r) {
    $routes[] = $r->methods()[0] . ' ' . $r->uri();
}
echo "total routes: " . count($routes) . "\n";
echo "first 12 raw:\n";
foreach (array_slice($routes, 0, 12) as $r) {
    echo "  [" . $r . "]\n";
}
echo "\nexact matches for the three gate paths:\n";
foreach (['/', '/builder', '/builder/bands'] as $p) {
    $uri = ltrim($p, '/');
    $hit = array_values(array_filter($routes, fn ($r) => ltrim(explode(' ', $r, 2)[1] ?? '', '/') === $uri));
    printf("  %-16s -> %s\n", $p, $hit === [] ? 'NO EXACT MATCH' : implode(', ', $hit));
}
