<?php
/*
 * READ-ONLY verification of database/scraped/featured-builds.json against production.
 * Verifies every part resolves, totals match live prices, every hard gate passes,
 * brands are clean, names/slugs/tags are unique, and non-APU builds carry 8 cores.
 */
use App\Services\BuildPolicyGate;
use Illuminate\Support\Facades\DB;
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/genie-prod-guard.php';
$g = new BuildPolicyGate();
$j = json_decode(file_get_contents(__DIR__.'/../database/scraped/featured-builds.json'), true);
$fail=0; $names=[]; $slugs=[]; $tagset=[];
foreach ($j['builds'] as $b) {
  $names[]=$b['name']; $slugs[]=$b['slug'];
  $tagset[]=sprintf("%s/%s/%s",$b['tags']['usage'],$b['tags']['resolution'],$b['tags']['tier']);
  $sum=0.0; $parts=[];
  foreach ($b['parts'] as $p) {
    $c = DB::table('components')->find($p['id']);
    if (!$c) { printf("MISSING id=%d in %s\n",$p['id'],$b['name']); $fail++; continue; }
    $sum += (float)$c->price; $parts[strtolower($p['type'])] = $c;
  }
  if (abs($sum - (float)$b['total']) > 0.01) { printf("TOTAL DRIFT %s: live %.2f vs %.2f\n",$b['name'],$sum,$b['total']); $fail++; }
  if (isset($parts['cpu']) && !$g->cpuAllowed($parts['cpu'])['ok']) { printf("CPU gate %s: %s\n",$b['name'],$g->cpuAllowed($parts['cpu'])['reason']); $fail++; }
  if (isset($parts['motherboard']) && !$g->boardAllowed($parts['motherboard'])['ok']) { printf("BOARD gate %s\n",$b['name']); $fail++; }
  if (isset($parts['ram']) && !$g->ramAllowed($parts['ram'],$b['socket'])['ok']) { printf("RAM gate %s: %s\n",$b['name'],$g->ramAllowed($parts['ram'],$b['socket'])['reason']); $fail++; }
  if (isset($parts['storage']) && !$g->storageAllowed($parts['storage'])['ok']) { printf("STORAGE gate %s\n",$b['name']); $fail++; }
  if (isset($parts['gpu']) && !$g->gpuAllowed($parts['gpu'])['ok']) { printf("GPU gate %s\n",$b['name']); $fail++; }
  foreach ($parts as $t=>$c) { if (!$g->brandAllowed($c->name)['ok']) { printf("BRAND %s %s\n",$b['name'],$c->name); $fail++; } }
  $cs = $parts['cpu']->specs; $cs = is_array($cs) ? $cs : (json_decode((string)$cs, true) ?: []); $cores = (int)($cs['cores'] ?? 0);
  if (!$b['integrated_graphics'] && $cores < 8) { printf("CORES %s only %d\n",$b['name'],$cores); $fail++; }
}
printf("\nbuilds: %d\n", count($j['builds']));
printf("unique names: %d / %d\n", count(array_unique($names)), count($names));
printf("unique slugs: %d / %d\n", count(array_unique($slugs)), count($slugs));
printf("unique tag combos: %d / %d\n", count(array_unique($tagset)), count($tagset));
printf("usages: %s\n", implode(', ', array_unique(array_column(array_column($j['builds'],'tags'),'usage_label'))));
printf("resolutions: %s\n", implode(', ', array_unique(array_column(array_column($j['builds'],'tags'),'resolution'))));
printf("tiers: %s\n", implode(', ', array_unique(array_column(array_column($j['builds'],'tags'),'tier_label'))));
printf("\n%s\n", $fail===0 ? "ALL FEATURED BUILDS PASS EVERY GATE" : "$fail FAILURES");
