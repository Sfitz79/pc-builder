<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

foreach ([39111, 39145, 39153, 39155, 40690] as $id) {
    $r = DB::table('components')->where('id', $id)->first();
    printf("#%d  %s\n     price    : GBP %s\n     source   : %s\n     image_url: %s\n",
        $id, substr((string) $r->name, 0, 50), number_format((float) $r->price, 2),
        substr((string) $r->source_url, 0, 78), $r->image_url ?: '(none)');
}