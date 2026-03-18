<?php

declare(strict_types=1);

function expo_h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function expo_table_exists(string $table): bool {
    $table = trim($table);
    if ($table === '' || !preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
    $rows = q(
        "SELECT 1
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = ?
         LIMIT 1",
        's',
        [$table]
    ) ?: [];
    return !empty($rows);
}

function expo_column_exists(string $table, string $column): bool {
    $table = trim($table);
    $column = trim($column);
    if ($table === '' || $column === '') return false;
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
    if (!preg_match('/^[A-Za-z0-9_]+$/', $column)) return false;

    $rows = q(
        "SELECT 1
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = ?
           AND column_name = ?
         LIMIT 1",
        'ss',
        [$table, $column]
    ) ?: [];

    return !empty($rows);
}

function expo_has_discard_column(): bool {
    static $has = null;
    if ($has === null) {
        $has = expo_column_exists('expo_piece_v1', 'is_discarded');
    }
    return $has;
}

function expo_template_options(): array {
    return [
        'hero_horizontal' => 'Hero horizontal',
        'hero_vertical_split' => 'Hero vertical split',
        'fullscreen_story' => 'Fullscreen story',
        'futbolistas_equipos' => 'Hero horizontal (legacy)',
    ];
}

function expo_template_descriptions(): array {
    return [
        'hero_horizontal' => 'Apertura clásica con hero ancho y cuerpo en grilla.',
        'hero_vertical_split' => 'Imagen hero vertical a la izquierda y texto a la derecha.',
        'fullscreen_story' => 'Recorrido lineal con piezas grandes y narrativa secuencial.',
        'futbolistas_equipos' => 'Compatibilidad con la plantilla anterior.',
    ];
}

function expo_normalize_template(?string $template): string {
    $template = trim((string)$template);
    if ($template === '' || $template === 'futbolistas_equipos') return 'hero_horizontal';
    if (!array_key_exists($template, expo_template_options())) return 'hero_horizontal';
    return $template;
}

function expo_get(int $id): ?array {
    $rows = q('SELECT * FROM expo_v1 WHERE id = ? LIMIT 1', 'i', [$id]) ?: [];
    $expo = $rows[0] ?? null;
    if (!$expo) return null;
    $expo['template_name'] = expo_normalize_template((string)($expo['template_name'] ?? ''));
    if (!isset($expo['hero_width_pct']) || $expo['hero_width_pct'] === null || $expo['hero_width_pct'] === '') {
        $expo['hero_width_pct'] = 38;
    }
    return $expo;
}

function expo_resequence_pieces(int $expoId): void {
    if ($expoId <= 0 || !expo_table_exists('expo_piece_v1')) return;

    $sql = "SELECT id
            FROM expo_piece_v1
            WHERE expo_id = ?";
    if (expo_has_discard_column()) {
        $sql .= " AND is_discarded = 0";
    }
    $sql .= " ORDER BY sort_order ASC, id ASC";

    $rows = q($sql, 'i', [$expoId]) ?: [];

    $n = 1;
    foreach ($rows as $r) {
        $pieceId = (int)($r['id'] ?? 0);
        if ($pieceId <= 0) continue;

        q(
            "UPDATE expo_piece_v1
             SET sort_order = ?
             WHERE id = ?",
            'ii',
            [$n, $pieceId]
        );
        $n++;
    }
}

function expo_get_collections(): array {
    if (!expo_table_exists('collections_v2')) return [];
    return q('SELECT id, title, description, created_at, updated_at FROM collections_v2 ORDER BY title ASC') ?: [];
}

function expo_image_ufi_from_key(string $imageKey): string {
    $imageKey = trim($imageKey);
    if ($imageKey === '' || strpos($imageKey, '_') === false) return '';

    [$barcode] = explode('_', $imageKey, 2);
    $barcode = trim($barcode);
    if ($barcode === '') return '';

    $rows = q(
        'SELECT ufi FROM items WHERE barcode = ? LIMIT 1',
        's',
        [$barcode]
    ) ?: [];

    return trim((string)($rows[0]['ufi'] ?? ''));
}

function expo_piece_thumb_url(array $piece): string {
    $pieceType = trim((string)($piece['piece_type'] ?? ''));
    $refId = trim((string)($piece['ref_id'] ?? ''));

    if ($pieceType !== 'imagen') return '';
    if ($refId === '' || strpos($refId, '_') === false) return '';

    [$barcode, $label] = explode('_', $refId, 2);
    $barcode = preg_replace('/[^A-Za-z0-9\-]/', '', $barcode);
    $label = preg_replace('/\D+/', '', $label);
    if ($barcode === '' || $label === '') return '';

    $ufi = expo_image_ufi_from_key($refId);
    $file = 'BNA_' . $barcode . '_' . str_pad($label, 3, '0', STR_PAD_LEFT) . '.jpg';

    if ($ufi === '') return '';
    return '/afdc_v2/bajas/' . rawurlencode($ufi) . '/' . rawurlencode($barcode) . '/' . rawurlencode($file);
}

function expo_get_pieces(int $expoId, bool $includeHidden = true): array {
    $sql = 'SELECT * FROM expo_piece_v1 WHERE expo_id = ?';
    if (expo_has_discard_column()) {
        $sql .= ' AND is_discarded = 0';
    }
    if (!$includeHidden) {
        $sql .= ' AND is_hidden = 0';
    }
    $sql .= ' ORDER BY sort_order ASC, id ASC';

    $rows = q($sql, 'i', [$expoId]) ?: [];
    foreach ($rows as &$row) {
        $row['thumb_url'] = expo_piece_thumb_url($row);
        $row['is_hero'] = 0;
    }
    unset($row);

    $expo = expo_get($expoId);
    $heroRef = trim((string)($expo['hero_ref_id'] ?? ''));
    foreach ($rows as &$row) {
        if ((string)$row['piece_type'] === (string)($expo['hero_type'] ?? 'imagen') && (string)$row['ref_id'] === $heroRef) {
            $row['is_hero'] = 1;
        }
    }
    unset($row);

    return $rows;
}

function expo_save_meta(int $expoId, array $data): void {
    $template = expo_normalize_template((string)($data['template_name'] ?? 'hero_horizontal'));
    $heroWidthPct = (int)($data['hero_width_pct'] ?? 38);
    if ($heroWidthPct < 22) $heroWidthPct = 22;
    if ($heroWidthPct > 65) $heroWidthPct = 65;

    $heroHeightPx = (int)($data['hero_height_px'] ?? 520);
    if ($heroHeightPx < 220) $heroHeightPx = 220;
    if ($heroHeightPx > 1200) $heroHeightPx = 1200;

    $overlay = (float)($data['hero_overlay_opacity'] ?? 0.35);
    if ($overlay < 0) $overlay = 0;
    if ($overlay > 1) $overlay = 1;

    q(
        'UPDATE expo_v1
         SET title = ?,
             slug = ?,
             kicker = ?,
             subtitle = ?,
             intro_html = ?,
             template_name = ?,
             source_collection_id = ?,
             hero_type = ?,
             hero_ref_id = ?,
             hero_pos_x = ?,
             hero_pos_y = ?,
             hero_height_px = ?,
             hero_width_pct = ?,
             hero_overlay_opacity = ?,
             cta_label = ?,
             cta_target = ?,
             status = ?
         WHERE id = ?',
        'ssssssissssiidsssi',
        [
            (string)($data['title'] ?? ''),
            (string)($data['slug'] ?? ''),
            (string)($data['kicker'] ?? ''),
            (string)($data['subtitle'] ?? ''),
            (string)($data['intro_html'] ?? ''),
            $template,
            (int)($data['source_collection_id'] ?? 0),
            (string)($data['hero_type'] ?? 'imagen'),
            (string)($data['hero_ref_id'] ?? ''),
            (string)($data['hero_pos_x'] ?? '50%'),
            (string)($data['hero_pos_y'] ?? '50%'),
            $heroHeightPx,
            $heroWidthPct,
            $overlay,
            (string)($data['cta_label'] ?? 'Explorar colección'),
            (string)($data['cta_target'] ?? 'viewer.html'),
            (string)($data['status'] ?? 'draft'),
            $expoId,
        ]
    );
}

function expo_import_collection(int $expoId, int $collectionId, bool $append = true): int {
    if ($collectionId <= 0) return 0;

    if (!$append) {
        if (expo_has_discard_column()) {
            q(
                'DELETE FROM expo_piece_v1
                 WHERE expo_id = ?
                   AND piece_type = "imagen"
                   AND is_discarded = 0',
                'i',
                [$expoId]
            );
        } else {
            q(
                'DELETE FROM expo_piece_v1
                 WHERE expo_id = ?
                   AND piece_type = "imagen"',
                'i',
                [$expoId]
            );
        }
    }

    $maxSql = 'SELECT COALESCE(MAX(sort_order), 0) AS mx FROM expo_piece_v1 WHERE expo_id = ?';
    if (expo_has_discard_column()) $maxSql .= ' AND is_discarded = 0';
    $maxRows = q($maxSql, 'i', [$expoId]) ?: [];
    $offset = (int)($maxRows[0]['mx'] ?? 0);

    $existing = [];
    $rows = q(
        'SELECT ref_id
         FROM expo_piece_v1
         WHERE expo_id = ?
           AND piece_type = "imagen"',
        'i',
        [$expoId]
    ) ?: [];
    foreach ($rows as $r) {
        $existing[(string)$r['ref_id']] = true;
    }

    $src = q(
        'SELECT image_key, position
         FROM collection_items_v2
         WHERE collection_id = ?
         ORDER BY position ASC, image_key ASC',
        'i',
        [$collectionId]
    ) ?: [];

    $inserted = 0;
    $n = 1;
    foreach ($src as $row) {
        $refId = trim((string)($row['image_key'] ?? ''));
        if ($refId === '' || isset($existing[$refId])) continue;

        if (expo_has_discard_column()) {
            q(
                'INSERT INTO expo_piece_v1 (expo_id, piece_type, ref_id, sort_order, is_featured, is_hidden, is_discarded)
                 VALUES (?, "imagen", ?, ?, 0, 0, 0)',
                'isi',
                [$expoId, $refId, $offset + $n]
            );
        } else {
            q(
                'INSERT INTO expo_piece_v1 (expo_id, piece_type, ref_id, sort_order, is_featured, is_hidden)
                 VALUES (?, "imagen", ?, ?, 0, 0)',
                'isi',
                [$expoId, $refId, $offset + $n]
            );
        }
        $inserted++;
        $n++;
    }

    q('UPDATE expo_v1 SET source_collection_id = ? WHERE id = ?', 'ii', [$collectionId, $expoId]);
    expo_resequence_pieces($expoId);

    return $inserted;
}

function expo_get_piece(int $pieceId): ?array {
    $sql = 'SELECT * FROM expo_piece_v1 WHERE id = ?';
    if (expo_has_discard_column()) {
        $sql .= ' AND is_discarded = 0';
    }
    $sql .= ' LIMIT 1';

    $rows = q($sql, 'i', [$pieceId]) ?: [];
    if (!$rows) return null;
    $piece = $rows[0];
    $piece['thumb_url'] = expo_piece_thumb_url($piece);
    return $piece;
}

function expo_save_piece(int $pieceId, array $data): void {
    q(
        'UPDATE expo_piece_v1
         SET title = ?, subtitle = ?, caption_html = ?, sort_order = ?, is_featured = ?, is_hidden = ?
         WHERE id = ?',
        'sssiiii',
        [
            (string)($data['title'] ?? ''),
            (string)($data['subtitle'] ?? ''),
            (string)($data['caption_html'] ?? ''),
            (int)($data['sort_order'] ?? 0),
            !empty($data['is_featured']) ? 1 : 0,
            !empty($data['is_hidden']) ? 1 : 0,
            $pieceId,
        ]
    );
}

function expo_delete_pieces(int $expoId, array $pieceIds): int {
    $ids = array_values(array_filter(array_map('intval', $pieceIds)));
    if (!$ids) return 0;

    if (expo_has_discard_column()) {
        foreach ($ids as $id) {
            q(
                'UPDATE expo_piece_v1
                 SET is_discarded = 1,
                     is_hidden = 1
                 WHERE expo_id = ? AND id = ?',
                'ii',
                [$expoId, $id]
            );
        }
    } else {
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $types = 'i' . str_repeat('i', count($ids));
        $params = array_merge([$expoId], $ids);
        q("DELETE FROM expo_piece_v1 WHERE expo_id = ? AND id IN ($marks)", $types, $params);
    }

    return count($ids);
}

function expo_set_hero_by_piece(int $expoId, int $pieceId): void {
    $piece = expo_get_piece($pieceId);
    if (!$piece) return;
    q(
        'UPDATE expo_v1 SET hero_type = ?, hero_ref_id = ? WHERE id = ?',
        'ssi',
        [(string)$piece['piece_type'], (string)$piece['ref_id'], $expoId]
    );
}

function expo_reorder_pieces(int $expoId, array $orderedIds): void {
    $pos = 1;
    foreach ($orderedIds as $id) {
        $id = (int)$id;
        if ($id <= 0) continue;

        $sql = 'UPDATE expo_piece_v1 SET sort_order = ? WHERE expo_id = ? AND id = ?';
        if (expo_has_discard_column()) {
            $sql .= ' AND is_discarded = 0';
        }
        q($sql, 'iii', [$pos, $expoId, $id]);
        $pos++;
    }
}

function expo_public_visible_pieces(int $expoId): array {
    return array_values(array_filter(expo_get_pieces($expoId, false), fn($p) => empty($p['is_hidden'])));
}

function expo_find_hero_url(array $expo, array $pieces): string {
    foreach ($pieces as $piece) {
        if ((string)$piece['piece_type'] === (string)($expo['hero_type'] ?? 'imagen') && (string)$piece['ref_id'] === (string)($expo['hero_ref_id'] ?? '')) {
            return (string)($piece['thumb_url'] ?? '');
        }
    }
    return !empty($pieces[0]['thumb_url']) ? (string)$pieces[0]['thumb_url'] : '';
}
