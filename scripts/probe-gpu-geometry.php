<?php
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
use App\Services\ThreeD\GpuGeometry;

$cases = [
  ['NVIDIA GeForce RTX 5080', ['length'=>336,'height'=>61,'thickness'=>42,'slots'=>2], ['memory'=>'16GB','memory_type'=>'GDDR7','fan_count'=>3], 'flagship 3-fan'],
  ['NVIDIA GeForce RTX 4060', ['length'=>245,'height'=>120,'thickness'=>40,'slots'=>2], ['memory'=>'8GB','memory_type'=>'GDDR6','fan_count'=>2], 'dual-fan'],
  ['NVIDIA GeForce RTX 3060', ['length'=>242,'height'=>113,'thickness'=>41,'slots'=>2], ['memory'=>'12GB','memory_type'=>'GDDR6','fan_count'=>2], 'dual-fan'],
];

$fail = 0;
foreach ($cases as [$name, $dims, $specs, $label]) {
  $g = new GpuGeometry();
  $out = $g->generate($dims, $specs, $name, 'gpu');
  $meshes = (array) $out['meshes'];

  $prims = []; $materials = [];
  foreach ($meshes as $m) {
    $p = strtolower((string)($m['p'] ?? '?'));
    $prims[$p] = ($prims[$p] ?? 0) + 1;
    if (isset($m['m'])) { $materials[(string)$m['m']] = ($materials[(string)$m['m']] ?? 0) + 1; }
  }
  $total = array_sum($prims);

  printf("%-28s %-14s meshes=%-4d primitives=%s\n", $name, $label, $total, json_encode($prims));

  // A recognisable GPU needs more than a box: shroud + fans + fins + backplate + pcb.
  $need = ['shroud','fan','fin','backplate','pcb'];
  $blob = strtolower(implode(' ', array_keys($materials)) . ' ' . json_encode($meshes));
  $missing = array_values(array_filter($need, fn($n) => strpos($blob, $n) === false));
  if ($missing) { echo "    MISSING FEATURES: " . implode(', ', $missing) . "\n"; $fail++; }
  if ($total < 15) { echo "    TOO FEW MESHES (<15) - would read as a box\n"; $fail++; }
}
echo $fail === 0 ? "\nGPU GEOMETRY VERIFIED: recognisable multi-part cards, not boxes\n"
                 : "\nGPU GEOMETRY FAILED on $fail case(s)\n";
exit($fail === 0 ? 0 : 1);
