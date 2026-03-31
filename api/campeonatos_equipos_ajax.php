<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/campeonatos_import_edit_repo.php';
header('Content-Type: application/json; charset=utf-8');

function cmp_eq_norm(string $text): string {
    $text = trim($text);
    if ($text === '') {
        return '';
    }

    if (function_exists('mb_strtolower')) {
        $text = mb_strtolower($text, 'UTF-8');
    } else {
        $text = strtolower($text);
    }

    if (class_exists('Normalizer')) {
        $tmp = \Normalizer::normalize($text, \Normalizer::FORM_D);
        if (is_string($tmp)) {
            $text = $tmp;
        }
    }

    $text = preg_replace('/\p{Mn}+/u', '', $text) ?? $text;

    $map = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n',
    ];

    return strtr($text, $map);
}

function cmp_eq_has_alias_table(mysqli $db): bool {
    $res = $db->query("SHOW TABLES LIKE 'equipos_alias'");
    if (!$res) return false;
    $ok = $res->num_rows > 0;
    $res->free();
    return $ok;
}

function cmp_eq_get_alias_catalog(mysqli $db): array {
    if (!cmp_eq_has_alias_table($db)) return [];
    $sql = "SELECT equipo_nombre, alias, alias_normalizado FROM equipos_alias ORDER BY equipo_nombre ASC, alias ASC, id ASC";
    $res = $db->query($sql);
    if (!$res) throw new RuntimeException('No se pudo leer equipos_alias: ' . $db->error);
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            'equipo_nombre' => trim((string)($row['equipo_nombre'] ?? '')),
            'alias' => trim((string)($row['alias'] ?? '')),
            'alias_normalizado' => trim((string)($row['alias_normalizado'] ?? '')),
        ];
    }
    $res->free();
    return $rows;
}

function cmp_eq_search_items(string $q): array {
    $db = cmp_edit_db();
    $qNorm = cmp_eq_norm($q);
    if ($qNorm === '') return [];

    $items = [];
    $seen = [];

    foreach (cmp_eq_get_alias_catalog($db) as $row) {
        $canonical = $row['equipo_nombre'];
        if ($canonical === '') continue;
        $match = false;
        foreach ([$row['equipo_nombre'], $row['alias'], $row['alias_normalizado']] as $candidate) {
            if ($candidate !== '' && strpos(cmp_eq_norm($candidate), $qNorm) !== false) {
                $match = true;
                break;
            }
        }
        if (!$match) continue;
        $key = cmp_eq_norm($canonical);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $items[] = $canonical;
        if (count($items) >= 20) return $items;
    }

    foreach (cmp_edit_get_distinct_team_options() as $team) {
        if (strpos(cmp_eq_norm($team), $qNorm) === false) continue;
        $key = cmp_eq_norm($team);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $items[] = $team;
        if (count($items) >= 20) break;
    }

    return $items;
}

function cmp_eq_create_team(string $name): array {
    $db = cmp_edit_db();
    if (!cmp_eq_has_alias_table($db)) {
        throw new RuntimeException('La tabla equipos_alias no existe.');
    }

    $name = trim($name);
    $norm = cmp_eq_norm($name);
    if ($norm === '') {
        throw new InvalidArgumentException('Nombre de equipo vacío.');
    }

    foreach (cmp_eq_get_alias_catalog($db) as $row) {
        $canonical = trim((string)$row['equipo_nombre']);
        foreach ([$row['equipo_nombre'], $row['alias'], $row['alias_normalizado']] as $candidate) {
            if ($candidate !== '' && cmp_eq_norm($candidate) === $norm) {
                return ['created' => false, 'item' => $canonical !== '' ? $canonical : $name];
            }
        }
    }

    $sql = "INSERT INTO equipos_alias (equipo_nombre, alias, alias_normalizado, notas, created_at, updated_at) VALUES (?, ?, ?, 'canónico', NOW(), NOW())";
    $stmt = $db->prepare($sql);
    if (!$stmt) throw new RuntimeException('No se pudo insertar en equipos_alias: ' . $db->error);
    $stmt->bind_param('sss', $name, $name, $norm);
    $stmt->execute();
    $stmt->close();

    return ['created' => true, 'item' => $name];
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'POST') {
        $action = trim((string)($_POST['action'] ?? 'create_team'));
        if ($action !== 'create_team') throw new InvalidArgumentException('Acción inválida.');
        $result = cmp_eq_create_team((string)($_POST['name'] ?? ''));
        echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $q = trim((string)($_GET['q'] ?? ''));
    echo json_encode(['ok' => true, 'items' => cmp_eq_search_items($q)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}