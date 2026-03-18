<?php
declare(strict_types=1);
require_once __DIR__ . '/../_common.php';
require_once __DIR__ . '/_lib.php';

$u = require_user_api();
require_csrf();

$in = read_json();
$name = trim((string)($in['name'] ?? ''));
$desc = trim((string)($in['description'] ?? ''));

if ($name === '') jexit(['ok'=>false,'error'=>'name_required'], 400);
if (mb_strlen($name) > 160) $name = mb_substr($name, 0, 160);

q(
    "INSERT INTO sets_v2 (owner_user_id, name, description, kind, created_at, updated_at)
     VALUES (?,?,?, 'temp', NOW(), NOW())",
    "iss",
    [(int)$u['id'], $name, $desc]
);

$idRow = q("SELECT LAST_INSERT_ID() AS id");
$newId = (int)($idRow[0]['id'] ?? 0);

jexit(['ok'=>true, 'id'=>$newId]);
