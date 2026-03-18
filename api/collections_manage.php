<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth_v2.php';
require_once __DIR__ . '/../inc/collections_repo.php';

header('Content-Type: application/json; charset=utf-8');

function jexit(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

afdc_v2_session_start();
$u = afdc_v2_current_user();
if (!$u) {
    jexit(['ok' => false, 'error' => 'auth'], 401);
}

$uid = (int)$u['id'];

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    jexit(['ok' => false, 'error' => 'method'], 405);
}

$csrf = (string)($_POST['csrf'] ?? '');

// Compatibilidad: algunos proyectos exponen token(), pero no validate().
if ($csrf === '') {
    jexit(['ok' => false, 'error' => 'csrf'], 400);
}

if (function_exists('afdc_v2_csrf_validate')) {
    if (!afdc_v2_csrf_validate($csrf)) {
        jexit(['ok' => false, 'error' => 'csrf'], 400);
    }
} else {
    $sessionToken = '';
    if (function_exists('afdc_v2_csrf_token')) {
        $sessionToken = (string)afdc_v2_csrf_token();
    } elseif (isset($_SESSION['csrf_token'])) {
        $sessionToken = (string)$_SESSION['csrf_token'];
    }

    if ($sessionToken === '' || !hash_equals($sessionToken, $csrf)) {
        jexit(['ok' => false, 'error' => 'csrf'], 400);
    }
}

$action = trim((string)($_POST['action'] ?? ''));
$collectionId = (int)($_POST['collection_id'] ?? 0);

if ($collectionId <= 0) {
    jexit(['ok' => false, 'error' => 'collection_id'], 400);
}

$mine = q(
    "SELECT id, title, description, is_public
     FROM collections_v2
     WHERE id=? AND created_by_user_id=?
     LIMIT 1",
    "ii",
    [$collectionId, $uid]
);

if (!$mine) {
    jexit(['ok' => false, 'error' => 'forbidden'], 403);
}

switch ($action) {
    case 'rename': {
        $title = trim((string)($_POST['title'] ?? ''));
        if ($title === '') {
            jexit(['ok' => false, 'error' => 'title'], 400);
        }

        $ok = v2_collection_rename($collectionId, $uid, $title);
        jexit([
            'ok' => $ok,
            'action' => 'rename',
            'collection_id' => $collectionId,
            'title' => $title,
        ], $ok ? 200 : 500);
    }

    case 'reorder': {

        $json = (string)($_POST['items_json'] ?? '');
        if ($json === '') {
            jexit(['ok'=>false,'error'=>'items'],400);
        }

        $items = json_decode($json, true);
        if (!is_array($items)) {
            jexit(['ok'=>false,'error'=>'json'],400);
        }

        $ok = v2_collection_reorder_items($collectionId, $uid, $items);

        jexit([
            'ok'=>$ok,
            'action'=>'reorder',
            'collection_id'=>$collectionId,
            'count'=>count($items)
        ], $ok ? 200 : 500);
    }


    case 'update': {
        $title = trim((string)($_POST['title'] ?? ''));
        $description = trim((string)($_POST['description'] ?? ''));
        $isPublic = (int)($_POST['is_public'] ?? 0);

        if ($title === '') {
            jexit(['ok' => false, 'error' => 'title'], 400);
        }

        $ok = v2_collection_update_meta($collectionId, $uid, $title, $description, $isPublic);
        jexit([
            'ok' => $ok,
            'action' => 'update',
            'collection_id' => $collectionId,
            'title' => $title,
            'description' => $description,
            'is_public' => $isPublic ? 1 : 0,
        ], $ok ? 200 : 500);
    }

    case 'clear': {
        $ok = v2_collection_clear($collectionId, $uid);
        jexit([
            'ok' => $ok,
            'action' => 'clear',
            'collection_id' => $collectionId,
        ], $ok ? 200 : 500);
    }

    case 'delete': {
        $ok = v2_collection_delete($collectionId, $uid);
        jexit([
            'ok' => $ok,
            'action' => 'delete',
            'collection_id' => $collectionId,
            'redirect' => rtrim((string)BASE_URL, '/') . '/colecciones.php',
        ], $ok ? 200 : 500);
    }

    default:
        jexit(['ok' => false, 'error' => 'action'], 400);
}
