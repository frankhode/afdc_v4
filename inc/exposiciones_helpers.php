<?php
declare(strict_types=1);

if (!function_exists('q')) {
  require_once dirname(__DIR__) . '/inc/bootstrap.php';
}

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

function expo_require_tables(): void {
  $missing = [];
  foreach (['expo_v1', 'expo_piece_v1', 'collections_v2', 'collection_items_v2'] as $t) {
    if (!expo_table_exists($t)) $missing[] = $t;
  }
  if ($missing) {
    http_response_code(500);
    echo 'Faltan tablas requeridas para Exposiciones: ' . expo_h(implode(', ', $missing));
    exit;
  }
}

function expo_get_all(): array {
  expo_require_tables();
  $sql = "SELECT e.*,
                 (SELECT COUNT(*) FROM expo_piece_v1 p WHERE p.expo_id = e.id AND p.is_hidden = 0) AS visible_pieces,
                 (SELECT COUNT(*) FROM expo_piece_v1 p WHERE p.expo_id = e.id) AS total_pieces
          FROM expo_v1 e
          ORDER BY e.updated_at DESC, e.id DESC";
  return q($sql) ?: [];
}

function expo_get(int $expoId): ?array {
  if ($expoId <= 0) return null;
  expo_require_tables();
  $rows = q("SELECT * FROM expo_v1 WHERE id=? LIMIT 1", 'i', [$expoId]) ?: [];
  return $rows[0] ?? null;
}

function expo_get_pieces(int $expoId): array {
  if ($expoId <= 0) return [];
  expo_require_tables();
  $rows = q(
    "SELECT p.*
     FROM expo_piece_v1 p
     WHERE p.expo_id=?
     ORDER BY p.sort_order ASC, p.id ASC",
    'i',
    [$expoId]
  ) ?: [];

  foreach ($rows as &$r) {
    $r['thumb_url'] = expo_piece_thumb_url($r);
  }
  unset($r);
  return $rows;
}

function expo_get_collections(): array {
  if (!expo_table_exists('collections_v2')) return [];
  return q("SELECT id, title, description, created_at, updated_at FROM collections_v2 ORDER BY title ASC") ?: [];
}

function expo_create(array $data): int {
  expo_require_tables();
  $slug = expo_make_slug((string)($data['slug'] ?? ''), (string)($data['title'] ?? ''));
  $title = trim((string)($data['title'] ?? ''));
  if ($title === '') $title = 'Nueva exposición';

  q(
    "INSERT INTO expo_v1
      (slug, title, kicker, subtitle, intro_html, template_name, source_collection_id,
       hero_type, hero_ref_id, hero_pos_x, hero_pos_y, hero_height_px, hero_overlay_opacity,
       cta_label, cta_target, status)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
    'ssssssissssdsiss',
    [
      $slug,
      $title,
      trim((string)($data['kicker'] ?? '')),
      trim((string)($data['subtitle'] ?? '')),
      (string)($data['intro_html'] ?? ''),
      trim((string)($data['template_name'] ?? 'futbolistas_equipos')),
      (int)($data['source_collection_id'] ?? 0) ?: null,
      trim((string)($data['hero_type'] ?? 'imagen')),
      trim((string)($data['hero_ref_id'] ?? '')),
      trim((string)($data['hero_pos_x'] ?? '50%')),
      trim((string)($data['hero_pos_y'] ?? '35%')),
      (int)($data['hero_height_px'] ?? 520),
      (float)($data['hero_overlay_opacity'] ?? 0.35),
      trim((string)($data['cta_label'] ?? 'Explorar colección')),
      trim((string)($data['cta_target'] ?? 'viewer.html')),
      trim((string)($data['status'] ?? 'draft')),
    ]
  );

  $row = q("SELECT LAST_INSERT_ID() AS id") ?: [];
  return (int)($row[0]['id'] ?? 0);
}

function expo_update(int $expoId, array $data): void {
  if ($expoId <= 0) return;
  $slug = expo_make_slug((string)($data['slug'] ?? ''), (string)($data['title'] ?? ''));
  $title = trim((string)($data['title'] ?? ''));
  if ($title === '') $title = 'Sin título';

  q(
    "UPDATE expo_v1
        SET slug=?,
            title=?,
            kicker=?,
            subtitle=?,
            intro_html=?,
            template_name=?,
            source_collection_id=?,
            hero_type=?,
            hero_ref_id=?,
            hero_pos_x=?,
            hero_pos_y=?,
            hero_height_px=?,
            hero_overlay_opacity=?,
            cta_label=?,
            cta_target=?,
            status=?
      WHERE id=?",
    'ssssssissssdsissi',
    [
      $slug,
      $title,
      trim((string)($data['kicker'] ?? '')),
      trim((string)($data['subtitle'] ?? '')),
      (string)($data['intro_html'] ?? ''),
      trim((string)($data['template_name'] ?? 'futbolistas_equipos')),
      (int)($data['source_collection_id'] ?? 0) ?: null,
      trim((string)($data['hero_type'] ?? 'imagen')),
      trim((string)($data['hero_ref_id'] ?? '')),
      trim((string)($data['hero_pos_x'] ?? '50%')),
      trim((string)($data['hero_pos_y'] ?? '35%')),
      (int)($data['hero_height_px'] ?? 520),
      (float)($data['hero_overlay_opacity'] ?? 0.35),
      trim((string)($data['cta_label'] ?? 'Explorar colección')),
      trim((string)($data['cta_target'] ?? 'viewer.html')),
      trim((string)($data['status'] ?? 'draft')),
      $expoId,
    ]
  );
}

function expo_delete(int $expoId): void {
  if ($expoId <= 0) return;
  q("DELETE FROM expo_v1 WHERE id=?", 'i', [$expoId]);
}

function expo_import_collection_images(int $expoId, int $collectionId, bool $append = true): int {
  if ($expoId <= 0 || $collectionId <= 0) return 0;

  if (!$append) {
    q("DELETE FROM expo_piece_v1 WHERE expo_id=? AND piece_type='imagen'", 'i', [$expoId]);
  }

  $maxRow = q("SELECT COALESCE(MAX(sort_order),0) AS m FROM expo_piece_v1 WHERE expo_id=?", 'i', [$expoId]) ?: [];
  $base = (int)($maxRow[0]['m'] ?? 0);

  $items = q(
    "SELECT image_key, position
     FROM collection_items_v2
     WHERE collection_id=?
       AND image_key IS NOT NULL
       AND image_key<>''
     ORDER BY position ASC, image_key ASC",
    'i',
    [$collectionId]
  ) ?: [];

  $inserted = 0;
  foreach ($items as $idx => $it) {
    $imageKey = trim((string)($it['image_key'] ?? ''));
    if ($imageKey === '') continue;

    $exists = q(
      "SELECT id FROM expo_piece_v1 WHERE expo_id=? AND piece_type='imagen' AND ref_id=? LIMIT 1",
      'is',
      [$expoId, $imageKey]
    ) ?: [];
    if ($exists) continue;

    $sort = $base + $idx + 1;
    q(
      "INSERT INTO expo_piece_v1 (expo_id, piece_type, ref_id, sort_order, is_featured, is_hidden)
       VALUES (?, 'imagen', ?, ?, 0, 0)",
      'isi',
      [$expoId, $imageKey, $sort]
    );
    $inserted++;
  }

  q("UPDATE expo_v1 SET source_collection_id = COALESCE(source_collection_id, ?) WHERE id=?", 'ii', [$collectionId, $expoId]);
  return $inserted;
}

function expo_add_piece(int $expoId, string $pieceType, string $refId): int {
  $pieceType = trim($pieceType);
  $refId = trim($refId);
  if ($expoId <= 0 || $pieceType === '' || $refId === '') return 0;

  $exists = q(
    "SELECT id FROM expo_piece_v1 WHERE expo_id=? AND piece_type=? AND ref_id=? LIMIT 1",
    'iss',
    [$expoId, $pieceType, $refId]
  ) ?: [];
  if ($exists) return (int)($exists[0]['id'] ?? 0);

  $maxRow = q("SELECT COALESCE(MAX(sort_order),0) AS m FROM expo_piece_v1 WHERE expo_id=?", 'i', [$expoId]) ?: [];
  $sort = (int)($maxRow[0]['m'] ?? 0) + 1;
  q(
    "INSERT INTO expo_piece_v1 (expo_id, piece_type, ref_id, sort_order, is_featured, is_hidden)
     VALUES (?, ?, ?, ?, 0, 0)",
    'issi',
    [$expoId, $pieceType, $refId, $sort]
  );
  $row = q("SELECT LAST_INSERT_ID() AS id") ?: [];
  return (int)($row[0]['id'] ?? 0);
}

function expo_update_piece(int $pieceId, array $data): void {
  if ($pieceId <= 0) return;
  q(
    "UPDATE expo_piece_v1
        SET title=?, subtitle=?, caption_html=?, sort_order=?, is_featured=?, is_hidden=?
      WHERE id=?",
    'sssiiii',
    [
      trim((string)($data['title'] ?? '')),
      trim((string)($data['subtitle'] ?? '')),
      (string)($data['caption_html'] ?? ''),
      (int)($data['sort_order'] ?? 0),
      !empty($data['is_featured']) ? 1 : 0,
      !empty($data['is_hidden']) ? 1 : 0,
      $pieceId,
    ]
  );
}

function expo_piece_delete(int $pieceId): void {
  if ($pieceId <= 0) return;
  q("DELETE FROM expo_piece_v1 WHERE id=?", 'i', [$pieceId]);
}

function expo_piece_move(int $pieceId, int $delta): void {
  if ($pieceId <= 0 || $delta === 0) return;
  $rows = q("SELECT id, expo_id, sort_order FROM expo_piece_v1 WHERE id=? LIMIT 1", 'i', [$pieceId]) ?: [];
  if (!$rows) return;
  $cur = $rows[0];
  $expoId = (int)$cur['expo_id'];
  $sort = (int)$cur['sort_order'];

  if ($delta < 0) {
    $swap = q(
      "SELECT id, sort_order FROM expo_piece_v1 WHERE expo_id=? AND sort_order < ? ORDER BY sort_order DESC, id DESC LIMIT 1",
      'ii',
      [$expoId, $sort]
    ) ?: [];
  } else {
    $swap = q(
      "SELECT id, sort_order FROM expo_piece_v1 WHERE expo_id=? AND sort_order > ? ORDER BY sort_order ASC, id ASC LIMIT 1",
      'ii',
      [$expoId, $sort]
    ) ?: [];
  }
  if (!$swap) return;

  $otherId = (int)$swap[0]['id'];
  $otherSort = (int)$swap[0]['sort_order'];
  q("UPDATE expo_piece_v1 SET sort_order=? WHERE id=?", 'ii', [$otherSort, $pieceId]);
  q("UPDATE expo_piece_v1 SET sort_order=? WHERE id=?", 'ii', [$sort, $otherId]);
}

function expo_image_ufi_from_key(string $imageKey): string {
  $imageKey = trim($imageKey);
  if ($imageKey === '' || strpos($imageKey, '_') === false) return '';

  [$barcode, $label] = explode('_', $imageKey, 2);
  $barcode = trim($barcode);

  if ($barcode === '') return '';

  if (expo_table_exists('items')) {
    $rows = q(
      "SELECT ufi
       FROM items
       WHERE barcode = ?
       LIMIT 1",
      's',
      [$barcode]
    ) ?: [];

    if (!empty($rows[0]['ufi'])) {
      return trim((string)$rows[0]['ufi']);
    }
  }

  return '';
}

function expo_piece_thumb_url(array $piece): string {
  $pieceType = trim((string)($piece['piece_type'] ?? ''));
  $refId = trim((string)($piece['ref_id'] ?? ''));

  if ($pieceType === 'imagen') {
    $imageKey = $refId;
    if ($imageKey === '' || strpos($imageKey, '_') === false) return '';

    [$barcode, $label] = explode('_', $imageKey, 2);
    $barcode = preg_replace('/[^A-Za-z0-9\-]/', '', $barcode);
    $label = preg_replace('/\D+/', '', $label);

    if ($barcode === '' || $label === '') return '';

    $file = 'BNA_' . $barcode . '_' . str_pad($label, 3, '0', STR_PAD_LEFT) . '.jpg';
    $ufi = expo_image_ufi_from_key($imageKey);

    if ($ufi !== '') {
      return '/afdc_v2/bajas/' . rawurlencode($ufi) . '/' . rawurlencode($barcode) . '/' . rawurlencode($file);
    }

    return '/afdc_v2/bajas/' . rawurlencode($barcode) . '/' . rawurlencode($file);
  }

  return '';
}

function expo_make_slug(string $slug, string $title = ''): string {
  $slug = trim($slug) !== '' ? trim($slug) : trim($title);
  $slug = mb_strtolower($slug, 'UTF-8');
  $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', $slug);
  $slug = trim((string)$slug, '-');
  if ($slug === '') $slug = 'expo';
  return substr($slug, 0, 120);
}

function expo_status_options(): array {
  return ['draft' => 'Borrador', 'published' => 'Publicada'];
}

function expo_template_options(): array {
  return ['futbolistas_equipos' => 'Futbolistas / Equipos'];
}
