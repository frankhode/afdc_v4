<?php
declare(strict_types=1);

require_once __DIR__ . '/campeonatos_helpers.php';
cmp_require_bootstrap_if_available();

function fsb_db(): mysqli {
    return cmp_db();
}

function fsb_estados(): array {
    return [
        'pendiente' => 'Pendiente',
        'partido_posible' => 'Partido posible',
        'futbol_general' => 'Fútbol general',
        'listo_para_relacionar' => 'Listo para relacionar',
        'vinculado' => 'Vinculado',
        'dudoso' => 'Dudoso',
        'descartado' => 'Descartado',
    ];
}

function fsb_estado_valido(string $estado): string {
    $estado = trim($estado);
    return array_key_exists($estado, fsb_estados()) ? $estado : 'pendiente';
}

function fsb_estado_label(string $estado): string {
    $estados = fsb_estados();
    return $estados[$estado] ?? $estado;
}

function fsb_limpiar_texto(?string $value): ?string {
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function fsb_request_filters(array $src): array {
    $estado = trim((string)($src['estado'] ?? ''));
    if ($estado !== '' && !array_key_exists($estado, fsb_estados())) {
        $estado = '';
    }

    return [
        'q' => trim((string)($src['q'] ?? '')),
        'estado' => $estado,
    ];
}

function fsb_buscar_titulos(string $q, int $limit = 80): array {
    $q = trim($q);
    if (mb_strlen($q, 'UTF-8') < 2) {
        return [];
    }

    $limit = max(1, min($limit, 200));
    $like = '%' . $q . '%';

    $db = fsb_db();

    $sql = "
        SELECT
            CAST(t.sys AS CHAR) AS sys,
            t.nroA,
            t.titulo,
            t.fecha,
            t.barcode,
            r.titulo245,

            c.id AS clasificacion_id,
            c.estado AS clasificacion_estado,

            fp.partidos_count,
            fp.partido_tituloReg,
            fp.partido_fecha,
            fp.partido_equipo1,
            fp.partido_equipo2,

            fv.vinculos_count,
            fv.vinculos_resumen,
            fv.vinculo_local,
            fv.vinculo_visitante

        FROM titulos t
        LEFT JOIN registros r ON r.sys = t.sys
        LEFT JOIN futbol_sobres_clasificacion c ON c.barcode = t.barcode

        LEFT JOIN (
            SELECT
                p.barcode,
                COUNT(*) AS partidos_count,
                MIN(p.tituloReg) AS partido_tituloReg,
                MIN(p.fecha) AS partido_fecha,
                MIN(p.equipo1) AS partido_equipo1,
                MIN(p.equipo2) AS partido_equipo2
            FROM partidos p
            GROUP BY p.barcode
        ) fp ON fp.barcode = t.barcode

        LEFT JOIN (
            SELECT
                v.partido_barcode AS barcode,
                COUNT(*) AS vinculos_count,
                GROUP_CONCAT(
                    DISTINCT CONCAT('#', v.importacion_id, ' ', COALESCE(ci.titulo_fuente, ''))
                    ORDER BY v.importacion_id ASC
                    SEPARATOR ' | '
                ) AS vinculos_resumen,
                MIN(ip.local_texto) AS vinculo_local,
                MIN(ip.visitante_texto) AS vinculo_visitante
            FROM cmp_importacion_partido_vinculos v
            LEFT JOIN cmp_importaciones ci ON ci.id = v.importacion_id
            LEFT JOIN cmp_importacion_partidos ip ON ip.id = v.importacion_partido_id
            GROUP BY v.partido_barcode
        ) fv ON fv.barcode = t.barcode

        WHERE
            t.barcode LIKE ?
            OR CAST(t.sys AS CHAR) LIKE ?
            OR COALESCE(t.nroA, '') LIKE ?
            OR COALESCE(t.titulo, '') LIKE ?
            OR COALESCE(t.fecha, '') LIKE ?
            OR COALESCE(r.titulo245, '') LIKE ?
        ORDER BY
            CASE WHEN t.barcode = ? THEN 0 ELSE 1 END,
            COALESCE(t.fecha, '') DESC,
            t.titulo ASC
        LIMIT ?
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param(
        'sssssssi',
        $like,
        $like,
        $like,
        $like,
        $like,
        $like,
        $q,
        $limit
    );

    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function fsb_get_titulo_por_barcode(string $barcode): ?array {
    $barcode = trim($barcode);
    if ($barcode === '') {
        return null;
    }

    $db = fsb_db();

    $sql = "
        SELECT
            CAST(t.sys AS CHAR) AS sys,
            t.nroA,
            t.titulo,
            t.fecha,
            t.barcode,
            r.titulo245
        FROM titulos t
        LEFT JOIN registros r ON r.sys = t.sys
        WHERE t.barcode = ?
        LIMIT 1
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('s', $barcode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row;
}

function fsb_get_partido_previo_por_barcode(string $barcode): ?array {
    $barcode = trim($barcode);
    if ($barcode === '') {
        return null;
    }

    $db = fsb_db();

    $sql = "
        SELECT
            p.barcode,
            p.tituloReg,
            p.fecha,
            p.equipo1,
            p.equipo2,
            p.cancha
        FROM partidos p
        WHERE p.barcode = ?
        LIMIT 1
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('s', $barcode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row;
}

function fsb_get_vinculo_previo_por_barcode(string $barcode): ?array {
    $barcode = trim($barcode);
    if ($barcode === '') {
        return null;
    }

    $db = fsb_db();

    $sql = "
        SELECT
            v.importacion_id,
            v.importacion_partido_id,
            ci.titulo_fuente,
            ip.local_texto,
            ip.visitante_texto
        FROM cmp_importacion_partido_vinculos v
        LEFT JOIN cmp_importaciones ci ON ci.id = v.importacion_id
        LEFT JOIN cmp_importacion_partidos ip ON ip.id = v.importacion_partido_id
        WHERE v.partido_barcode = ?
        LIMIT 1
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('s', $barcode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    return $row;
}

function fsb_agregar_a_bandeja(string $barcode): int {
    $barcode = trim($barcode);
    if ($barcode === '') {
        throw new InvalidArgumentException('Falta barcode.');
    }

    $titulo = fsb_get_titulo_por_barcode($barcode);
    if (!$titulo) {
        throw new RuntimeException('No se encontró el barcode en titulos.');
    }

    $sys = trim((string)($titulo['sys'] ?? ''));
    if ($sys === '') {
        throw new RuntimeException('El título no tiene sys.');
    }

    $partidoPrevio = fsb_get_partido_previo_por_barcode($barcode);
    $vinculoPrevio = fsb_get_vinculo_previo_por_barcode($barcode);

    $estado = 'pendiente';
    if ($vinculoPrevio) {
        $estado = 'vinculado';
    } elseif ($partidoPrevio) {
        $estado = 'listo_para_relacionar';
    }

    $equipo1 = fsb_limpiar_texto($partidoPrevio['equipo1'] ?? null);
    $equipo2 = fsb_limpiar_texto($partidoPrevio['equipo2'] ?? null);
    $fechaSugerida = fsb_limpiar_texto($partidoPrevio['fecha'] ?? null);
    $fechaPrecision = $fechaSugerida !== null ? 'exacta' : null;
    $campeonatoSugerido = fsb_limpiar_texto($partidoPrevio['tituloReg'] ?? null);

    $db = fsb_db();

    $sql = "
        INSERT INTO futbol_sobres_clasificacion (
            sys,
            barcode,
            estado,
            equipo1_texto,
            equipo2_texto,
            fecha_sugerida,
            fecha_precision,
            campeonato_sugerido_texto,
            creado_en,
            actualizado_en
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
        )
        ON DUPLICATE KEY UPDATE
            sys = VALUES(sys),

            estado = CASE
                WHEN estado = 'pendiente' AND VALUES(estado) IN ('listo_para_relacionar', 'vinculado')
                    THEN VALUES(estado)
                ELSE estado
            END,

            equipo1_texto = CASE
                WHEN COALESCE(equipo1_texto, '') = '' THEN VALUES(equipo1_texto)
                ELSE equipo1_texto
            END,

            equipo2_texto = CASE
                WHEN COALESCE(equipo2_texto, '') = '' THEN VALUES(equipo2_texto)
                ELSE equipo2_texto
            END,

            fecha_sugerida = CASE
                WHEN COALESCE(fecha_sugerida, '') = '' THEN VALUES(fecha_sugerida)
                ELSE fecha_sugerida
            END,

            fecha_precision = CASE
                WHEN COALESCE(fecha_precision, '') = '' THEN VALUES(fecha_precision)
                ELSE fecha_precision
            END,

            campeonato_sugerido_texto = CASE
                WHEN COALESCE(campeonato_sugerido_texto, '') = '' THEN VALUES(campeonato_sugerido_texto)
                ELSE campeonato_sugerido_texto
            END,

            actualizado_en = NOW()
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param(
        'ssssssss',
        $sys,
        $barcode,
        $estado,
        $equipo1,
        $equipo2,
        $fechaSugerida,
        $fechaPrecision,
        $campeonatoSugerido
    );

    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare('SELECT id FROM futbol_sobres_clasificacion WHERE barcode = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('s', $barcode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['id'] ?? 0);
}

function fsb_autocompletar_desde_existente(int $id): void {
    if ($id <= 0) {
        throw new InvalidArgumentException('ID inválido.');
    }

    $db = fsb_db();

    $stmt = $db->prepare('SELECT barcode FROM futbol_sobres_clasificacion WHERE id = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if (!$row) {
        throw new RuntimeException('No se encontró la clasificación.');
    }

    $barcode = trim((string)$row['barcode']);
    if ($barcode === '') {
        throw new RuntimeException('La clasificación no tiene barcode.');
    }

    $partidoPrevio = fsb_get_partido_previo_por_barcode($barcode);
    $vinculoPrevio = fsb_get_vinculo_previo_por_barcode($barcode);

    if (!$partidoPrevio && !$vinculoPrevio) {
        throw new RuntimeException('No se encontró información previa en partidos ni vínculos.');
    }

    $estado = null;
    if ($vinculoPrevio) {
        $estado = 'vinculado';
    } elseif ($partidoPrevio) {
        $estado = 'listo_para_relacionar';
    }

    $equipo1 = fsb_limpiar_texto($partidoPrevio['equipo1'] ?? null);
    $equipo2 = fsb_limpiar_texto($partidoPrevio['equipo2'] ?? null);
    $fechaSugerida = fsb_limpiar_texto($partidoPrevio['fecha'] ?? null);
    $fechaPrecision = $fechaSugerida !== null ? 'exacta' : null;
    $campeonatoSugerido = fsb_limpiar_texto($partidoPrevio['tituloReg'] ?? null);

    $sql = "
        UPDATE futbol_sobres_clasificacion
        SET
            estado = CASE
                WHEN estado IN ('pendiente', 'partido_posible', 'listo_para_relacionar') AND ? IS NOT NULL
                    THEN ?
                ELSE estado
            END,

            equipo1_texto = CASE
                WHEN COALESCE(equipo1_texto, '') = '' THEN ?
                ELSE equipo1_texto
            END,

            equipo2_texto = CASE
                WHEN COALESCE(equipo2_texto, '') = '' THEN ?
                ELSE equipo2_texto
            END,

            fecha_sugerida = CASE
                WHEN COALESCE(fecha_sugerida, '') = '' THEN ?
                ELSE fecha_sugerida
            END,

            fecha_precision = CASE
                WHEN COALESCE(fecha_precision, '') = '' THEN ?
                ELSE fecha_precision
            END,

            campeonato_sugerido_texto = CASE
                WHEN COALESCE(campeonato_sugerido_texto, '') = '' THEN ?
                ELSE campeonato_sugerido_texto
            END,

            actualizado_en = NOW()
        WHERE id = ?
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param(
        'sssssssi',
        $estado,
        $estado,
        $equipo1,
        $equipo2,
        $fechaSugerida,
        $fechaPrecision,
        $campeonatoSugerido,
        $id
    );

    $stmt->execute();
    $stmt->close();
}

function fsb_listar_bandeja(array $filters = [], int $limit = 200): array {
    $limit = max(1, min($limit, 500));
    $q = trim((string)($filters['q'] ?? ''));
    $estado = trim((string)($filters['estado'] ?? ''));

    $where = ['1=1'];
    $types = '';
    $params = [];

    if ($estado !== '' && array_key_exists($estado, fsb_estados())) {
        $where[] = 'c.estado = ?';
        $types .= 's';
        $params[] = $estado;
    }

    if ($q !== '') {
        $like = '%' . $q . '%';
        $where[] = "(
            c.barcode LIKE ?
            OR CAST(c.sys AS CHAR) LIKE ?
            OR COALESCE(t.nroA, '') LIKE ?
            OR COALESCE(t.titulo, '') LIKE ?
            OR COALESCE(t.fecha, '') LIKE ?
            OR COALESCE(r.titulo245, '') LIKE ?
            OR COALESCE(c.equipo1_texto, '') LIKE ?
            OR COALESCE(c.equipo2_texto, '') LIKE ?
            OR COALESCE(c.equipo_principal_texto, '') LIKE ?
            OR COALESCE(c.campeonato_sugerido_texto, '') LIKE ?
            OR COALESCE(c.notas, '') LIKE ?
            OR COALESCE(fp.partido_tituloReg, '') LIKE ?
            OR COALESCE(fp.partido_equipo1, '') LIKE ?
            OR COALESCE(fp.partido_equipo2, '') LIKE ?
            OR COALESCE(fv.vinculos_resumen, '') LIKE ?
        )";
        $types .= 'sssssssssssssss';
        for ($i = 0; $i < 15; $i++) {
            $params[] = $like;
        }
    }

    $db = fsb_db();

    $sql = "
        SELECT
            c.*,
            t.titulo,
            t.fecha,
            t.nroA,
            r.titulo245,

            fp.partidos_count,
            fp.partido_tituloReg,
            fp.partido_fecha,
            fp.partido_equipo1,
            fp.partido_equipo2,

            fv.vinculos_count,
            fv.vinculos_resumen,
            fv.vinculo_local,
            fv.vinculo_visitante

        FROM futbol_sobres_clasificacion c
        LEFT JOIN titulos t ON t.barcode = c.barcode
        LEFT JOIN registros r ON r.sys = c.sys

        LEFT JOIN (
            SELECT
                p.barcode,
                COUNT(*) AS partidos_count,
                MIN(p.tituloReg) AS partido_tituloReg,
                MIN(p.fecha) AS partido_fecha,
                MIN(p.equipo1) AS partido_equipo1,
                MIN(p.equipo2) AS partido_equipo2
            FROM partidos p
            GROUP BY p.barcode
        ) fp ON fp.barcode = c.barcode

        LEFT JOIN (
            SELECT
                v.partido_barcode AS barcode,
                COUNT(*) AS vinculos_count,
                GROUP_CONCAT(
                    DISTINCT CONCAT('#', v.importacion_id, ' ', COALESCE(ci.titulo_fuente, ''))
                    ORDER BY v.importacion_id ASC
                    SEPARATOR ' | '
                ) AS vinculos_resumen,
                MIN(ip.local_texto) AS vinculo_local,
                MIN(ip.visitante_texto) AS vinculo_visitante
            FROM cmp_importacion_partido_vinculos v
            LEFT JOIN cmp_importaciones ci ON ci.id = v.importacion_id
            LEFT JOIN cmp_importacion_partidos ip ON ip.id = v.importacion_partido_id
            GROUP BY v.partido_barcode
        ) fv ON fv.barcode = c.barcode

        WHERE " . implode(' AND ', $where) . "
        ORDER BY
            FIELD(c.estado, 'pendiente', 'partido_posible', 'listo_para_relacionar', 'futbol_general', 'dudoso', 'vinculado', 'descartado'),
            c.actualizado_en DESC,
            c.id DESC
        LIMIT ?
    ";

    $types .= 'i';
    $params[] = $limit;

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function fsb_metricas(): array {
    $db = fsb_db();

    $sql = "
        SELECT estado, COUNT(*) AS total
        FROM futbol_sobres_clasificacion
        GROUP BY estado
        ORDER BY estado ASC
    ";

    $res = $db->query($sql);
    if (!$res) {
        throw new RuntimeException($db->error);
    }

    $out = [
        'total' => 0,
        'por_estado' => [],
    ];

    while ($row = $res->fetch_assoc()) {
        $estado = (string)$row['estado'];
        $total = (int)$row['total'];
        $out['por_estado'][$estado] = $total;
        $out['total'] += $total;
    }

    $res->free();

    return $out;
}

function fsb_actualizar_clasificacion(int $id, array $data): void {
    if ($id <= 0) {
        throw new InvalidArgumentException('ID inválido.');
    }

    $estado = fsb_estado_valido((string)($data['estado'] ?? 'pendiente'));

    $equipo1 = fsb_limpiar_texto($data['equipo1_texto'] ?? null);
    $equipo2 = fsb_limpiar_texto($data['equipo2_texto'] ?? null);
    $equipoPrincipal = fsb_limpiar_texto($data['equipo_principal_texto'] ?? null);
    $fechaSugerida = fsb_limpiar_texto($data['fecha_sugerida'] ?? null);
    $fechaPrecision = fsb_limpiar_texto($data['fecha_precision'] ?? null);
    $campeonatoSugerido = fsb_limpiar_texto($data['campeonato_sugerido_texto'] ?? null);
    $notas = fsb_limpiar_texto($data['notas'] ?? null);

    $db = fsb_db();

    $sql = "
        UPDATE futbol_sobres_clasificacion
        SET
            estado = ?,
            equipo1_texto = ?,
            equipo2_texto = ?,
            equipo_principal_texto = ?,
            fecha_sugerida = ?,
            fecha_precision = ?,
            campeonato_sugerido_texto = ?,
            notas = ?,
            actualizado_en = NOW()
        WHERE id = ?
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param(
        'ssssssssi',
        $estado,
        $equipo1,
        $equipo2,
        $equipoPrincipal,
        $fechaSugerida,
        $fechaPrecision,
        $campeonatoSugerido,
        $notas,
        $id
    );

    $stmt->execute();
    $stmt->close();
}

function fsb_eliminar_de_bandeja(int $id): void {
    if ($id <= 0) {
        throw new InvalidArgumentException('ID inválido.');
    }

    $db = fsb_db();

    $stmt = $db->prepare('DELETE FROM futbol_sobres_clasificacion WHERE id = ?');
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
}

function fsb_autocomplete_equipos(string $q, int $limit = 12): array {
    $q = trim($q);
    if (mb_strlen($q, 'UTF-8') < 2) {
        return [];
    }

    $limit = max(1, min($limit, 30));
    $like = '%' . $q . '%';
    $prefix = $q . '%';

    $db = fsb_db();

    $sql = "
        SELECT
            nombre,
            SUM(uso) AS uso,
            GROUP_CONCAT(DISTINCT fuente ORDER BY fuente SEPARATOR ', ') AS fuentes
        FROM (
            SELECT TRIM(equipo1) AS nombre, COUNT(*) AS uso, 'partidos.equipo1' AS fuente
            FROM partidos
            WHERE TRIM(COALESCE(equipo1, '')) <> '' AND equipo1 LIKE ?
            GROUP BY TRIM(equipo1)

            UNION ALL

            SELECT TRIM(equipo2) AS nombre, COUNT(*) AS uso, 'partidos.equipo2' AS fuente
            FROM partidos
            WHERE TRIM(COALESCE(equipo2, '')) <> '' AND equipo2 LIKE ?
            GROUP BY TRIM(equipo2)

            UNION ALL

            SELECT TRIM(local_texto) AS nombre, COUNT(*) AS uso, 'importacion.local' AS fuente
            FROM cmp_importacion_partidos
            WHERE TRIM(COALESCE(local_texto, '')) <> '' AND local_texto LIKE ?
            GROUP BY TRIM(local_texto)

            UNION ALL

            SELECT TRIM(visitante_texto) AS nombre, COUNT(*) AS uso, 'importacion.visitante' AS fuente
            FROM cmp_importacion_partidos
            WHERE TRIM(COALESCE(visitante_texto, '')) <> '' AND visitante_texto LIKE ?
            GROUP BY TRIM(visitante_texto)
        ) s
        WHERE nombre <> ''
        GROUP BY nombre
        ORDER BY
            CASE WHEN nombre LIKE ? THEN 0 ELSE 1 END,
            uso DESC,
            nombre ASC
        LIMIT ?
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('sssssi', $like, $like, $like, $like, $prefix, $limit);
    $stmt->execute();

    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function fsb_autocomplete_campeonatos(string $q, int $limit = 12): array {
    $q = trim($q);
    if (mb_strlen($q, 'UTF-8') < 2) {
        return [];
    }

    $limit = max(1, min($limit, 30));
    $like = '%' . $q . '%';
    $prefix = $q . '%';

    $db = fsb_db();

    $sql = "
        SELECT
            nombre,
            SUM(uso) AS uso,
            GROUP_CONCAT(DISTINCT fuente ORDER BY fuente SEPARATOR ', ') AS fuentes
        FROM (
            SELECT TRIM(tituloReg) AS nombre, COUNT(*) AS uso, 'partidos.tituloReg' AS fuente
            FROM partidos
            WHERE TRIM(COALESCE(tituloReg, '')) <> '' AND tituloReg LIKE ?
            GROUP BY TRIM(tituloReg)

            UNION ALL

            SELECT TRIM(titulo_fuente) AS nombre, COUNT(*) AS uso, 'cmp_importaciones' AS fuente
            FROM cmp_importaciones
            WHERE TRIM(COALESCE(titulo_fuente, '')) <> '' AND titulo_fuente LIKE ?
            GROUP BY TRIM(titulo_fuente)

            UNION ALL

            SELECT TRIM(titulo245) AS nombre, COUNT(*) AS uso, 'registros.titulo245' AS fuente
            FROM registros
            WHERE TRIM(COALESCE(titulo245, '')) <> '' AND titulo245 LIKE ?
            GROUP BY TRIM(titulo245)
        ) s
        WHERE nombre <> ''
        GROUP BY nombre
        ORDER BY
            CASE WHEN nombre LIKE ? THEN 0 ELSE 1 END,
            uso DESC,
            nombre ASC
        LIMIT ?
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('ssssi', $like, $like, $like, $prefix, $limit);
    $stmt->execute();

    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}