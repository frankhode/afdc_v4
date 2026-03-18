<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth_v2.php';

afdc_v2_session_start();

$u = afdc_v2_current_user();
if (!$u) {
    bv_json(['ok' => false, 'error' => 'No autorizado.'], 401);
}

$tipo = trim((string)($_GET['tipo'] ?? ''));
$qtxt = trim((string)($_GET['q'] ?? ''));

$tiposValidos = ['foto', 'sobre', 'coleccion', 'expo'];
if (!in_array($tipo, $tiposValidos, true)) {
    bv_json(['ok' => false, 'error' => 'Tipo inválido.'], 400);
}

$minChars = ($tipo === 'foto' || $tipo === 'sobre') ? 3 : 2;
if (mb_strlen($qtxt) < $minChars) {
    bv_json(['ok' => false, 'error' => 'Texto de búsqueda demasiado corto.'], 400);
}

$like = '%' . $qtxt . '%';
$items = [];

if ($tipo === 'foto') {
    $rows = q(
        "SELECT nombramiento, inv
         FROM digitales
         WHERE nombramiento LIKE ?
            OR inv LIKE ?
         ORDER BY
            CASE
              WHEN nombramiento = ? THEN 0
              WHEN nombramiento LIKE ? THEN 1
              WHEN inv = ? THEN 2
              ELSE 3
            END,
            nombramiento ASC
         LIMIT 20",
        'sssss',
        [$like, $like, $qtxt, $like, $qtxt]
    );

    foreach ($rows as $r) {
        $nom = trim((string)($r['nombramiento'] ?? ''));
        if ($nom === '') {
            continue;
        }
        $inv = trim((string)($r['inv'] ?? ''));
        $items[] = [
            'key' => $nom,
            'title' => $nom,
            'subtitle' => $inv !== '' ? ('Sobre: ' . $inv) : '',
        ];
    }

    bv_json(['ok' => true, 'items' => bv_unique_items($items)]);
}

if ($tipo === 'sobre') {
    $rows1 = q(
        "SELECT barcode
         FROM items
         WHERE barcode LIKE ?
         ORDER BY
            CASE WHEN barcode = ? THEN 0 ELSE 1 END,
            barcode ASC
         LIMIT 20",
        'ss',
        [$like, $qtxt]
    );

    foreach ($rows1 as $r) {
        $barcode = trim((string)($r['barcode'] ?? ''));
        if ($barcode === '') {
            continue;
        }
        $items[] = [
            'key' => $barcode,
            'title' => $barcode,
            'subtitle' => 'items.barcode',
        ];
    }

    $rows2 = q(
        "SELECT DISTINCT inv
         FROM digitales
         WHERE inv LIKE ?
         ORDER BY
            CASE WHEN inv = ? THEN 0 ELSE 1 END,
            inv ASC
         LIMIT 20",
        'ss',
        [$like, $qtxt]
    );

    foreach ($rows2 as $r) {
        $inv = trim((string)($r['inv'] ?? ''));
        if ($inv === '') {
            continue;
        }
        $items[] = [
            'key' => $inv,
            'title' => $inv,
            'subtitle' => 'digitales.inv',
        ];
    }

    bv_json(['ok' => true, 'items' => array_slice(bv_unique_items($items), 0, 20)]);
}

if ($tipo === 'coleccion') {
    $rows = q(
        "SELECT id, title
         FROM collections_v2
         WHERE title LIKE ?
         ORDER BY
            CASE WHEN title = ? THEN 0 ELSE 1 END,
            title ASC
         LIMIT 20",
        'ss',
        [$like, $qtxt]
    );

    foreach ($rows as $r) {
        $id = (string)($r['id'] ?? '');
        $title = trim((string)($r['title'] ?? ''));
        if ($id === '' || $title === '') {
            continue;
        }
        $items[] = [
            'key' => $id,
            'title' => $title,
            'subtitle' => 'ID ' . $id,
        ];
    }

    bv_json(['ok' => true, 'items' => $items]);
}

if ($tipo === 'expo') {
    $rows = q(
        "SELECT id, title, slug
         FROM expo_v1
         WHERE title LIKE ?
            OR slug LIKE ?
         ORDER BY
            CASE
              WHEN title = ? THEN 0
              WHEN slug = ? THEN 1
              ELSE 2
            END,
            title ASC
         LIMIT 20",
        'ssss',
        [$like, $like, $qtxt, $qtxt]
    );

    foreach ($rows as $r) {
        $id = (string)($r['id'] ?? '');
        $title = trim((string)($r['title'] ?? ''));
        if ($id === '' || $title === '') {
            continue;
        }
        $slug = trim((string)($r['slug'] ?? ''));
        $items[] = [
            'key' => $id,
            'title' => $title,
            'subtitle' => $slug !== '' ? $slug : ('ID ' . $id),
        ];
    }

    bv_json(['ok' => true, 'items' => $items]);
}

bv_json(['ok' => true, 'items' => []]);

function bv_json(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bv_unique_items(array $items): array {
    $seen = [];
    $out = [];

    foreach ($items as $item) {
        $key = (string)($item['key'] ?? '');
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $item;
    }

    return $out;
}