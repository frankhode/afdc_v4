<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/futbol_sobres_clasificacion_repo.php';

header('Content-Type: application/json; charset=utf-8');

$type = trim((string)($_GET['type'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));

try {
    if (mb_strlen($q, 'UTF-8') < 2) {
        echo json_encode([
            'ok' => true,
            'items' => [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    switch ($type) {
        case 'equipo':
            $rows = fsb_autocomplete_equipos($q);
            break;

        case 'campeonato':
            $rows = fsb_autocomplete_campeonatos($q);
            break;

        default:
            throw new InvalidArgumentException('Tipo de autocomplete inválido.');
    }

    $items = [];
    foreach ($rows as $row) {
        $nombre = trim((string)($row['nombre'] ?? ''));
        if ($nombre === '') {
            continue;
        }

        $items[] = [
            'value' => $nombre,
            'label' => $nombre,
            'uso' => (int)($row['uso'] ?? 0),
            'fuentes' => (string)($row['fuentes'] ?? ''),
        ];
    }

    echo json_encode([
        'ok' => true,
        'items' => $items,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);

    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'items' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}