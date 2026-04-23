<?php
declare(strict_types=1);
require_once __DIR__ . '/campeonatos_import_repo.php';
require_once __DIR__ . '/campeonatos_helpers.php';
cmp_require_bootstrap_if_available();

require_once __DIR__ . '/campeonatos_entidades_repo.php';
require_once __DIR__ . '/campeonatos_relacion_sobres_repo.php';

function cmp_si_db(): mysqli {
    return cmp_db();
}

function cmp_si_h(string $value): string {
    return cmp_h($value);
}

function cmp_si_ensure_import(): int {
    $db = cmp_si_db();

    $sql = "SELECT id
            FROM cmp_importaciones
            WHERE titulo_fuente = 'Sin identificar'
            ORDER BY id ASC
            LIMIT 1";
    $res = $db->query($sql);
    if (!$res) {
        throw new RuntimeException($db->error);
    }
    $row = $res->fetch_assoc() ?: null;
    $res->free();

    if ($row) {
        return (int)$row['id'];
    }

    $fuenteTipo = 'manual_tecnico';
    $fuenteUrl = null;
    $tituloFuente = 'Sin identificar';
    $estado = 'activo';
    $textoCrudo = '';
    $treeJson = json_encode([
        'technical_container' => true,
        'container_type' => 'sin_identificar',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $stmt = $db->prepare(
        "INSERT INTO cmp_importaciones
         (fuente_tipo, fuente_url, titulo_fuente, temporada_detectada, estado, texto_crudo, tree_json, creado_en, actualizado_en)
         VALUES (?, ?, ?, NULL, ?, ?, ?, NOW(), NOW())"
    );
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('ssssss', $fuenteTipo, $fuenteUrl, $tituloFuente, $estado, $textoCrudo, $treeJson);
    $stmt->execute();
    $importId = (int)$stmt->insert_id;
    $stmt->close();

    return $importId;
}

function cmp_si_ensure_target_node(int $importId): int {
    if ($importId <= 0) {
        throw new InvalidArgumentException('Importación inválida.');
    }

    $db = cmp_si_db();

    $stmt = $db->prepare(
        "SELECT id
         FROM cmp_importacion_nodos
         WHERE importacion_id = ?
           AND tipo = 'fecha'
           AND label = 'Pendientes'
           AND COALESCE(is_deleted,0) = 0
         ORDER BY id ASC
         LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }
    $stmt->bind_param('i', $importId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if ($row) {
        return (int)$row['id'];
    }

    $db->begin_transaction();
    try {
        $stmtRoot = $db->prepare(
            "SELECT id
             FROM cmp_importacion_nodos
             WHERE importacion_id = ?
               AND parent_id IS NULL
               AND tipo = 'temporada'
               AND label = 'Sin identificar'
               AND COALESCE(is_deleted,0) = 0
             ORDER BY id ASC
             LIMIT 1"
        );
        if (!$stmtRoot) {
            throw new RuntimeException($db->error);
        }
        $stmtRoot->bind_param('i', $importId);
        $stmtRoot->execute();
        $root = $stmtRoot->get_result()->fetch_assoc() ?: null;
        $stmtRoot->close();

        $rootId = 0;

        if ($root) {
            $rootId = (int)$root['id'];
        } else {
            $metaRoot = json_encode([
                'technical_container' => true,
                'container_type' => 'sin_identificar',
                'role' => 'root',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $ordenRoot = 1;
            $nivelRoot = 0;
            $tipoRoot = 'temporada';
            $subtipoRoot = null;
            $labelRoot = 'Sin identificar';
            $textoOriginalRoot = null;

            $stmtInsRoot = $db->prepare(
                "INSERT INTO cmp_importacion_nodos
                 (importacion_id, parent_id, tipo, subtipo, label, orden, nivel, texto_original, meta_json, is_manual, is_deleted, creado_en, actualizado_en)
                 VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, 1, 0, NOW(), NOW())"
            );
            if (!$stmtInsRoot) {
                throw new RuntimeException($db->error);
            }
            $stmtInsRoot->bind_param(
                'isssiiss',
                $importId,
                $tipoRoot,
                $subtipoRoot,
                $labelRoot,
                $ordenRoot,
                $nivelRoot,
                $textoOriginalRoot,
                $metaRoot
            );
            $stmtInsRoot->execute();
            $rootId = (int)$stmtInsRoot->insert_id;
            $stmtInsRoot->close();
        }

        $metaNode = json_encode([
            'technical_container' => true,
            'container_type' => 'sin_identificar',
            'role' => 'target_node',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ordenNode = 1;
        $nivelNode = 1;
        $tipoNode = 'fecha';
        $subtipoNode = 'pendientes';
        $labelNode = 'Pendientes';
        $textoOriginalNode = null;

        $stmtInsNode = $db->prepare(
            "INSERT INTO cmp_importacion_nodos
             (importacion_id, parent_id, tipo, subtipo, label, orden, nivel, texto_original, meta_json, is_manual, is_deleted, creado_en, actualizado_en)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, NOW(), NOW())"
        );
        if (!$stmtInsNode) {
            throw new RuntimeException($db->error);
        }
        $stmtInsNode->bind_param(
            'iissiisss',
            $importId,
            $rootId,
            $tipoNode,
            $subtipoNode,
            $labelNode,
            $ordenNode,
            $nivelNode,
            $textoOriginalNode,
            $metaNode
        );
        $stmtInsNode->execute();
        $nodeId = (int)$stmtInsNode->insert_id;
        $stmtInsNode->close();

        $db->commit();
        return $nodeId;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function cmp_si_search_registros(string $q, int $limit = 50): array {
    $db = cmp_si_db();
    $q = trim($q);
    $limit = max(1, min(200, $limit));

    $sql = "
        SELECT
            p.tituloReg,
            COUNT(*) AS sobres_count
        FROM partidos p
        WHERE TRIM(COALESCE(p.tituloReg, '')) <> ''
    ";

    $types = '';
    $params = [];

    if ($q !== '') {
        $sql .= " AND p.tituloReg LIKE ? ";
        $types .= 's';
        $params[] = '%' . $q . '%';
    }

    $sql .= "
        GROUP BY p.tituloReg
        ORDER BY sobres_count DESC, p.tituloReg ASC
        LIMIT {$limit}
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function cmp_si_list_sobres_by_registro(string $tituloReg): array {
    $tituloReg = trim($tituloReg);
    if ($tituloReg === '') {
        return [];
    }

    $importId = cmp_si_ensure_import();
    $db = cmp_si_db();

    $sql = "
        SELECT
            p.barcode,
            p.tituloSobre AS titulo,
            p.tituloReg,
            p.fecha,
            p.equipo1,
            p.equipo2,
            p.cancha,
            CASE WHEN v.id IS NULL THEN 0 ELSE 1 END AS ya_en_sin_identificar
        FROM partidos p
        LEFT JOIN cmp_importacion_partido_vinculos v
          ON v.importacion_id = ?
         AND v.partido_barcode = p.barcode
        WHERE p.tituloReg = ?
          AND v.id IS NULL
        ORDER BY
          COALESCE(p.fecha, '') ASC,
          COALESCE(p.equipo1, '') ASC,
          COALESCE(p.equipo2, '') ASC,
          p.barcode ASC
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }
    $stmt->bind_param('is', $importId, $tituloReg);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function cmp_si_barcode_already_loaded(string $barcode, ?int $importId = null): bool {
    $barcode = trim($barcode);
    if ($barcode === '') {
        return false;
    }

    $importId = $importId && $importId > 0 ? $importId : cmp_si_ensure_import();
    $db = cmp_si_db();

    $stmt = $db->prepare(
        "SELECT id
         FROM cmp_importacion_partido_vinculos
         WHERE importacion_id = ?
           AND partido_barcode = ?
         LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }
    $stmt->bind_param('is', $importId, $barcode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row !== null;
}

function cmp_si_next_match_order(int $importId, int $nodeId): int {
    $db = cmp_si_db();
    $stmt = $db->prepare(
        "SELECT COALESCE(MAX(orden), 0) + 1 AS next_order
         FROM cmp_importacion_partidos
         WHERE importacion_id = ?
           AND nodo_id = ?"
    );
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }
    $stmt->bind_param('ii', $importId, $nodeId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: ['next_order' => 1];
    $stmt->close();

    return (int)$row['next_order'];
}

function cmp_si_attach_barcode_link(int $importId, int $matchId, string $barcode, string $tituloReg = ''): void {
    $barcode = trim($barcode);
    $tituloReg = trim($tituloReg);

    if ($importId <= 0 || $matchId <= 0 || $barcode === '') {
        throw new InvalidArgumentException('Datos inválidos para vincular el sobre.');
    }

    $db = cmp_si_db();

    $stmtSobre = $db->prepare("SELECT barcode, tituloReg FROM partidos WHERE barcode = ? LIMIT 1");
    if (!$stmtSobre) {
        throw new RuntimeException($db->error);
    }
    $stmtSobre->bind_param('s', $barcode);
    $stmtSobre->execute();
    $sobre = $stmtSobre->get_result()->fetch_assoc() ?: null;
    $stmtSobre->close();

    if (!$sobre) {
        throw new RuntimeException('No existe el sobre a vincular.');
    }

    $tituloRegReal = trim((string)($sobre['tituloReg'] ?? ''));
    if ($tituloRegReal === '') {
        $tituloRegReal = $tituloReg;
    }

    $stmtDel = $db->prepare(
        "DELETE FROM cmp_importacion_partido_vinculos
         WHERE importacion_id = ?
           AND partido_barcode = ?"
    );
    if (!$stmtDel) {
        throw new RuntimeException($db->error);
    }
    $stmtDel->bind_param('is', $importId, $barcode);
    $stmtDel->execute();
    $stmtDel->close();

    $origen = 'manual_boton';
    $observacion = null;

    $stmtIns = $db->prepare(
        "INSERT INTO cmp_importacion_partido_vinculos
         (importacion_id, importacion_partido_id, partido_barcode, tituloReg, origen, observacion)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    if (!$stmtIns) {
        throw new RuntimeException($db->error);
    }
    $stmtIns->bind_param('iissss', $importId, $matchId, $barcode, $tituloRegReal, $origen, $observacion);
    $stmtIns->execute();
    $stmtIns->close();
}

function cmp_si_create_match(array $payload): int {
    $barcode = trim((string)($payload['barcode'] ?? ''));
    $tituloReg = trim((string)($payload['tituloReg'] ?? ''));
    $localTexto = trim((string)($payload['local_texto'] ?? ''));
    $visitanteTexto = trim((string)($payload['visitante_texto'] ?? ''));
    $observacion = trim((string)($payload['observacion_manual'] ?? ''));
    $fechaTexto = trim((string)($payload['fecha_texto'] ?? ''));
    $tituloSobre = trim((string)($payload['tituloSobre'] ?? ''));

    $localEntidadId = isset($payload['local_entidad_id']) && (int)$payload['local_entidad_id'] > 0
        ? (int)$payload['local_entidad_id']
        : null;
    $visitanteEntidadId = isset($payload['visitante_entidad_id']) && (int)$payload['visitante_entidad_id'] > 0
        ? (int)$payload['visitante_entidad_id']
        : null;

    if ($barcode === '') {
        throw new InvalidArgumentException('Falta el barcode del sobre.');
    }
    if ($localTexto === '' || $visitanteTexto === '') {
        throw new InvalidArgumentException('Local y visitante son obligatorios.');
    }

    $importId = cmp_si_ensure_import();

    if (cmp_si_barcode_already_loaded($barcode, $importId)) {
        throw new RuntimeException('Ese sobre ya está cargado en Sin identificar.');
    }

    $nodeId = cmp_si_ensure_target_node($importId);
    $orden = cmp_si_next_match_order($importId, $nodeId);

    $localNorm = cmp_ent_normalize_name($localTexto);
    $visitNorm = cmp_ent_normalize_name($visitanteTexto);

    if ($localEntidadId === null) {
        $resolved = cmp_ent_resolve_name($localTexto);
        if ($resolved) {
            $localEntidadId = (int)$resolved['id'];
        }
    }

    if ($visitanteEntidadId === null) {
        $resolved = cmp_ent_resolve_name($visitanteTexto);
        if ($resolved) {
            $visitanteEntidadId = (int)$resolved['id'];
        }
    }

    $meta = [
        'technical_container' => true,
        'container_type' => 'sin_identificar',
        'barcode_source' => $barcode,
        'titulo_sobre' => $tituloSobre,
        'fecha_texto' => $fechaTexto,
    ];
    $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($metaJson === false) {
        throw new RuntimeException('No se pudo serializar meta_json.');
    }

    $sourceLine = $tituloSobre !== '' ? $tituloSobre : ('Sobre ' . $barcode);

    $db = cmp_si_db();
    $db->begin_transaction();
    try {
        $stmt = $db->prepare(
            "INSERT INTO cmp_importacion_partidos
             (importacion_id, nodo_id, orden, local_texto, local_entidad_id, local_normalizado, visitante_texto, visitante_entidad_id, visitante_normalizado, goles_local, goles_visitante, fuente_linea, observacion_manual, meta_json, is_manual_edit, creado_en, actualizado_en)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?, ?, 1, NOW(), NOW())"
        );
        if (!$stmt) {
            throw new RuntimeException($db->error);
        }

        $obsFinal = $observacion;
        if ($fechaTexto !== '') {
            $obsFinal = trim($obsFinal . ($obsFinal !== '' ? ' | ' : '') . 'Fecha: ' . $fechaTexto);
        }

        $stmt->bind_param(
            'iiisississss',
            $importId,
            $nodeId,
            $orden,
            $localTexto,
            $localEntidadId,
            $localNorm,
            $visitanteTexto,
            $visitanteEntidadId,
            $visitNorm,
            $sourceLine,
            $obsFinal,
            $metaJson
        );
        $stmt->execute();
        $matchId = (int)$stmt->insert_id;
        $stmt->close();

        cmp_si_attach_barcode_link($importId, $matchId, $barcode, $tituloReg);

        $db->commit();
        return $matchId;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function cmp_si_is_import(int $importId): bool {
    if ($importId <= 0) {
        return false;
    }

    $db = cmp_si_db();
    $stmt = $db->prepare("SELECT id FROM cmp_importaciones WHERE id = ? AND titulo_fuente = 'Sin identificar' LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('i', $importId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row !== null;
}

function cmp_si_list_destination_imports(): array {
    $db = cmp_si_db();
    $sql = "
        SELECT id, titulo_fuente, temporada_detectada, estado
        FROM cmp_importaciones
        WHERE titulo_fuente <> 'Sin identificar'
        ORDER BY
          CASE WHEN temporada_detectada IS NULL THEN 1 ELSE 0 END,
          temporada_detectada DESC,
          id DESC
    ";
    $res = $db->query($sql);
    if (!$res) {
        throw new RuntimeException($db->error);
    }

    $rows = $res->fetch_all(MYSQLI_ASSOC);
    $res->free();

    return $rows;
}

function cmp_si_list_destination_nodes(int $importId): array {
    if ($importId <= 0) {
        return [];
    }

    $nodes = cmp_import_get_nodes($importId);
    if (!$nodes) {
        return [];
    }

    $byId = [];
    foreach ($nodes as $node) {
        $byId[(int)$node['id']] = $node;
    }

    $rows = [];
    foreach ($nodes as $node) {
        if ((string)($node['tipo'] ?? '') !== 'fecha') {
            continue;
        }
        if ((int)($node['is_deleted'] ?? 0) !== 0) {
            continue;
        }

        $trail = [];
        $currentId = (int)$node['id'];

        while (isset($byId[$currentId])) {
            $current = $byId[$currentId];
            $label = trim((string)($current['label'] ?? ''));
            if ($label !== '') {
                array_unshift($trail, $label);
            }
            $parentId = $current['parent_id'];
            if ($parentId === null) {
                break;
            }
            $currentId = (int)$parentId;
        }

        $rows[] = [
            'id' => (int)$node['id'],
            'label' => implode(' > ', $trail),
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        return strcmp((string)$a['label'], (string)$b['label']);
    });

    return $rows;
}

function cmp_si_move_match_to_import_node(int $matchId, int $targetImportId, int $targetNodeId): void {
    if ($matchId <= 0 || $targetImportId <= 0 || $targetNodeId <= 0) {
        throw new InvalidArgumentException('Faltan datos para mover el partido.');
    }

    $db = cmp_si_db();

    $stmtMatch = $db->prepare("
        SELECT id, importacion_id, nodo_id
        FROM cmp_importacion_partidos
        WHERE id = ?
        LIMIT 1
    ");
    if (!$stmtMatch) {
        throw new RuntimeException($db->error);
    }
    $stmtMatch->bind_param('i', $matchId);
    $stmtMatch->execute();
    $match = $stmtMatch->get_result()->fetch_assoc() ?: null;
    $stmtMatch->close();

    if (!$match) {
        throw new RuntimeException('El partido no existe.');
    }

    $sourceImportId = (int)$match['importacion_id'];
    if (!cmp_si_is_import($sourceImportId)) {
        throw new RuntimeException('Ese partido no pertenece a Sin identificar.');
    }

    $stmtImport = $db->prepare("
        SELECT id, titulo_fuente
        FROM cmp_importaciones
        WHERE id = ?
        LIMIT 1
    ");
    if (!$stmtImport) {
        throw new RuntimeException($db->error);
    }
    $stmtImport->bind_param('i', $targetImportId);
    $stmtImport->execute();
    $targetImport = $stmtImport->get_result()->fetch_assoc() ?: null;
    $stmtImport->close();

    if (!$targetImport) {
        throw new RuntimeException('La importación destino no existe.');
    }

    if ((string)($targetImport['titulo_fuente'] ?? '') === 'Sin identificar') {
        throw new RuntimeException('El destino no puede ser Sin identificar.');
    }

    $stmtNode = $db->prepare("
        SELECT id, importacion_id, tipo, COALESCE(is_deleted,0) AS is_deleted
        FROM cmp_importacion_nodos
        WHERE id = ?
        LIMIT 1
    ");
    if (!$stmtNode) {
        throw new RuntimeException($db->error);
    }
    $stmtNode->bind_param('i', $targetNodeId);
    $stmtNode->execute();
    $targetNode = $stmtNode->get_result()->fetch_assoc() ?: null;
    $stmtNode->close();

    if (!$targetNode) {
        throw new RuntimeException('El nodo destino no existe.');
    }

    if ((int)$targetNode['importacion_id'] !== $targetImportId) {
        throw new RuntimeException('El nodo destino no pertenece al campeonato elegido.');
    }

    if ((string)$targetNode['tipo'] !== 'fecha') {
        throw new RuntimeException('Solo se puede mover a nodos de tipo fecha.');
    }

    if ((int)$targetNode['is_deleted'] !== 0) {
        throw new RuntimeException('El nodo destino está eliminado.');
    }

    $stmtOrder = $db->prepare("
        SELECT COALESCE(MAX(orden), 0) + 1 AS next_order
        FROM cmp_importacion_partidos
        WHERE importacion_id = ?
          AND nodo_id = ?
    ");
    if (!$stmtOrder) {
        throw new RuntimeException($db->error);
    }
    $stmtOrder->bind_param('ii', $targetImportId, $targetNodeId);
    $stmtOrder->execute();
    $orderRow = $stmtOrder->get_result()->fetch_assoc() ?: ['next_order' => 1];
    $stmtOrder->close();
    $nextOrder = (int)$orderRow['next_order'];

    $db->begin_transaction();
    try {
        $stmtUpdMatch = $db->prepare("
            UPDATE cmp_importacion_partidos
            SET importacion_id = ?,
                nodo_id = ?,
                orden = ?,
                actualizado_en = NOW()
            WHERE id = ?
        ");
        if (!$stmtUpdMatch) {
            throw new RuntimeException($db->error);
        }
        $stmtUpdMatch->bind_param('iiii', $targetImportId, $targetNodeId, $nextOrder, $matchId);
        $stmtUpdMatch->execute();
        $stmtUpdMatch->close();

        $stmtUpdLinks = $db->prepare("
            UPDATE cmp_importacion_partido_vinculos
            SET importacion_id = ?
            WHERE importacion_partido_id = ?
              AND importacion_id = ?
        ");
        if (!$stmtUpdLinks) {
            throw new RuntimeException($db->error);
        }
        $stmtUpdLinks->bind_param('iii', $targetImportId, $matchId, $sourceImportId);
        $stmtUpdLinks->execute();
        $stmtUpdLinks->close();

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}