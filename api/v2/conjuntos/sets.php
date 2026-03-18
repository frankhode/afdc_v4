<?php
declare(strict_types=1);
require_once __DIR__ . '/../_common.php';
require_once __DIR__ . '/_lib.php';

$u = require_user_api();

$def = v2_sets_ensure_arevisar((int)$u['id']);
$sets = v2_sets_list((int)$u['id']);

$out = [];
foreach ($sets as $s) {
    $out[] = [
        'id' => (int)$s['id'],
        'name' => (string)$s['name'],
        'description' => (string)($s['description'] ?? ''),
        'kind' => (string)($s['kind'] ?? 'temp'),
    ];
}

jexit([
    'ok' => true,
    'default_set_id' => (int)$def['id'],
    'sets' => $out,
]);
