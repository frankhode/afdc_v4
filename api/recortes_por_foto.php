<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth_v2.php';

afdc_v2_session_start();

$u = afdc_v2_current_user();
if (!$u) {
    rp_json(['ok' => false, 'error' => 'No autorizado.'], 401);
}

$usuarioId = (int)($u['id'] ?? 0);
if ($usuarioId <= 0) {
    rp_json(['ok' => false, 'error' => 'Usuario inválido.'], 401);
}

$imageKey = trim((string)($_GET['image_key'] ?? ''));
if ($imageKey === '') {
    rp_json(['ok' => false, 'error' => 'Falta image_key.'], 400);
}

$rows = q(
    "SELECT
        r.id,
        r.barcode,
        r.pag_izq,
        r.pag_der,
        r.recorte_origen_id
     FROM recorte_vinculos rv
     INNER JOIN recortes r
       ON r.id = rv.recorte_id
     WHERE rv.tipo_objeto = 'foto'
       AND rv.objeto_id = ?
       AND r.usuario_id = ?
     ORDER BY r.id DESC",
    'si',
    [$imageKey, $usuarioId]
);

$base = rtrim((string)BASE_URL, '/');
$items = [];

foreach (($rows ?: []) as $r) {
    $rid = (int)($r['id'] ?? 0);
    if ($rid <= 0) continue;

    $items[] = [
        'id' => $rid,
        'title' => 'Recorte #' . $rid,
        'subtitle' => 'Barcode: ' . (string)($r['barcode'] ?? ''),
        'thumb' => $base . '/api/recorte_render.php?id=' . $rid . '&modo=crop&maxw=420&q=82',
        'href' => $base . '/vincular_recorte.php?id=' . $rid,
    ];
}

rp_json([
    'ok' => true,
    'items' => $items,
]);

function rp_json(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}