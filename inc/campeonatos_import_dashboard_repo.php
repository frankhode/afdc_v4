<?php
declare(strict_types=1);

require_once __DIR__ . '/campeonatos_helpers.php';
require_once __DIR__ . '/campeonatos_import_repo.php';

function cmp_dashboard_import_filters_from_request(): array {
    return [
        'q' => trim((string)($_GET['q'] ?? '')),
        'temporada' => trim((string)($_GET['temporada'] ?? '')),
        'estado' => trim((string)($_GET['estado'] ?? '')),
        'fuente_tipo' => trim((string)($_GET['fuente_tipo'] ?? '')),
    ];
}

function cmp_dashboard_import_list(array $filters = []): array {
    $db = cmp_db();

    $sql = "
        SELECT
            i.*,
            COALESCE(n.nodos_count, 0) AS nodes_count,
            COALESCE(p.partidos_count, 0) AS matches_count,
            COALESCE(p.ignorados_count, 0) AS ignored_matches_count,
            COALESCE(g.goles_count, 0) AS goals_count
        FROM cmp_importaciones i
        LEFT JOIN (
            SELECT importacion_id, COUNT(*) AS nodos_count
            FROM cmp_importacion_nodos
            GROUP BY importacion_id
        ) n ON n.importacion_id = i.id
        LEFT JOIN (
            SELECT
                importacion_id,
                COUNT(*) AS partidos_count,
                SUM(CASE WHEN estado = 'ignorado' THEN 1 ELSE 0 END) AS ignorados_count
            FROM cmp_importacion_partidos
            GROUP BY importacion_id
        ) p ON p.importacion_id = i.id
        LEFT JOIN (
            SELECT importacion_id, COUNT(*) AS goles_count
            FROM cmp_importacion_goles
            GROUP BY importacion_id
        ) g ON g.importacion_id = i.id
        WHERE 1=1
    ";

    $types = '';
    $params = [];

    if (($filters['q'] ?? '') !== '') {
        $sql .= " AND (
            i.titulo_fuente LIKE ?
            OR i.fuente_url LIKE ?
            OR CAST(i.id AS CHAR) LIKE ?
        )";
        $q = '%' . $filters['q'] . '%';
        $types .= 'sss';
        $params[] = $q;
        $params[] = $q;
        $params[] = $q;
    }

    if (($filters['temporada'] ?? '') !== '') {
        $sql .= " AND CAST(i.temporada_detectada AS CHAR) = ?";
        $types .= 's';
        $params[] = $filters['temporada'];
    }

    if (($filters['estado'] ?? '') !== '') {
        $sql .= " AND i.estado = ?";
        $types .= 's';
        $params[] = $filters['estado'];
    }

    if (($filters['fuente_tipo'] ?? '') !== '') {
        $sql .= " AND i.fuente_tipo = ?";
        $types .= 's';
        $params[] = $filters['fuente_tipo'];
    }

    $sql .= " ORDER BY i.id DESC";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($rows as &$row) {
        $audit = cmp_dashboard_build_coverage_from_tree(
            (string)($row['texto_crudo'] ?? ''),
            cmp_dashboard_tree_from_json((string)($row['tree_json'] ?? ''))
        );

        $summary = $audit['summary'] ?? [];

        $row['audit_total_lines'] = (int)($summary['total'] ?? 0);
        $row['audit_used_lines'] = (int)($summary['used'] ?? 0);
        $row['audit_unused_lines'] = (int)($summary['unused'] ?? 0);
        $row['audit_dudosa_lines'] = (int)($summary['suspicious'] ?? 0);
        $row['audit_coverage_pct'] = isset($summary['coverage']) ? (float)$summary['coverage'] : null;
    }
    unset($row);

    return $rows;
}

function cmp_dashboard_tree_from_json(string $treeJson): array {
    if (trim($treeJson) === '') {
        return [];
    }

    $decoded = json_decode($treeJson, true);
    return is_array($decoded) ? $decoded : [];
}

function cmp_dashboard_build_coverage_from_tree(string $rawText, array $tree): array {
    $lines = preg_split("/\\r\\n|\\r|\\n/", $rawText);
    if (!is_array($lines)) {
        $lines = [];
    }

    $total = 0;
    $empty = 0;
    foreach ($lines as $line) {
        if (trim((string)$line) === '') {
            $empty++;
            continue;
        }
        $total++;
    }

    $usedLineNos = [];

    $walk = function (array $nodes) use (&$walk, &$usedLineNos): void {
        foreach ($nodes as $node) {
            $lineNo = $node['line_no'] ?? null;
            if ($lineNo !== null && $lineNo !== '') {
                $usedLineNos[(int)$lineNo] = true;
            }

            if (!empty($node['children']) && is_array($node['children'])) {
                $walk($node['children']);
            }
        }
    };

    $walk($tree);

    $used = count($usedLineNos);
    if ($used > $total) {
        $used = $total;
    }

    $unused = max(0, $total - $used);
    $coverage = $total > 0 ? round(($used * 100) / $total, 1) : null;

    return [
        'summary' => [
            'total' => $total,
            'used' => $used,
            'unused' => $unused,
            'suspicious' => 0,
            'empty' => $empty,
            'coverage' => $coverage,
        ],
    ];
}

function cmp_dashboard_import_metrics(array $rows): array {
    $metrics = [
        'total' => 0,
        'sin_partidos' => 0,
        'con_ignorados' => 0,
        'cobertura_baja' => 0,
        'por_estado' => [],
    ];

    foreach ($rows as $row) {
        $metrics['total']++;

        $state = (string)($row['estado'] ?? 'sin_estado');
        $metrics['por_estado'][$state] = ($metrics['por_estado'][$state] ?? 0) + 1;

        if ((int)($row['matches_count'] ?? 0) === 0) {
            $metrics['sin_partidos']++;
        }

        if ((int)($row['ignored_matches_count'] ?? 0) > 0) {
            $metrics['con_ignorados']++;
        }

        $coverage = $row['audit_coverage_pct'];
        if ($coverage !== null && (float)$coverage < 80.0) {
            $metrics['cobertura_baja']++;
        }
    }

    return $metrics;
}

function cmp_dashboard_import_distinct_values(string $field): array {
    $allowed = ['temporada_detectada', 'estado', 'fuente_tipo'];
    if (!in_array($field, $allowed, true)) {
        throw new InvalidArgumentException('Campo no permitido.');
    }

    $db = cmp_db();
    $sql = "
        SELECT DISTINCT {$field} AS v
        FROM cmp_importaciones
        WHERE {$field} IS NOT NULL
          AND {$field} <> ''
        ORDER BY {$field} ASC
    ";

    $res = $db->query($sql);
    if (!$res) {
        throw new RuntimeException($db->error);
    }

    $values = [];
    while ($row = $res->fetch_assoc()) {
        $values[] = (string)$row['v'];
    }

    return $values;
}

function cmp_dashboard_table_exists(string $tableName): bool {
    $db = cmp_db();

    $tableName = trim($tableName);
    if ($tableName === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
        throw new InvalidArgumentException('Nombre de tabla inválido.');
    }

    $escaped = $db->real_escape_string($tableName);
    $sql = "SHOW TABLES LIKE '{$escaped}'";
    $res = $db->query($sql);

    if (!$res) {
        throw new RuntimeException($db->error);
    }

    return (bool)$res->fetch_row();
}

function cmp_dashboard_import_hard_delete(int $importId): void {
    if ($importId <= 0) {
        throw new InvalidArgumentException('Importación inválida.');
    }

    $db = cmp_db();
    $db->begin_transaction();

    try {
        $tables = [
            'cmp_importacion_goles',
            'cmp_importacion_partidos',
            'cmp_importacion_nodos',
        ];

        foreach ($tables as $table) {
            $sql = "DELETE FROM {$table} WHERE importacion_id = ?";
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException($db->error);
            }
            $stmt->bind_param('i', $importId);
            $stmt->execute();
            $stmt->close();
        }

        $optionalTables = [
            'cmp_importacion_auditoria_lineas',
        ];

        foreach ($optionalTables as $table) {
            if (!cmp_dashboard_table_exists($table)) {
                continue;
            }
            $sql = "DELETE FROM {$table} WHERE importacion_id = ?";
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                throw new RuntimeException($db->error);
            }
            $stmt->bind_param('i', $importId);
            $stmt->execute();
            $stmt->close();
        }

        $stmt = $db->prepare("DELETE FROM cmp_importaciones WHERE id = ?");
        if (!$stmt) {
            throw new RuntimeException($db->error);
        }
        $stmt->bind_param('i', $importId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected < 1) {
            throw new RuntimeException('No se encontró la importación para borrar.');
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}