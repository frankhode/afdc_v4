<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth_v2.php';
require_once __DIR__ . '/inc/collections_repo.php';

afdc_v2_session_start();

$u = afdc_v2_current_user();
if (!$u) {
    die('Acceso no autorizado');
}

$usuarioId = (int)($u['id'] ?? 0);
if ($usuarioId <= 0) {
    die('Usuario inválido');
}

$recorteId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($recorteId <= 0) {
    die('Recorte inválido');
}

$csrf = afdc_v2_csrf_token();

function vr_h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function vr_tipo_label(string $tipo): string {
    switch ($tipo) {
        case 'foto': return 'Foto';
        case 'sobre': return 'Sobre';
        case 'coleccion': return 'Colección';
        case 'expo': return 'Exposición';
        default: return $tipo;
    }
}

function vr_recorte_get_owned(int $recorteId, int $usuarioId): ?array {
    $rows = q(
        "SELECT *
         FROM recortes
         WHERE id = ?
           AND usuario_id = ?
         LIMIT 1",
        'ii',
        [$recorteId, $usuarioId]
    );
    return $rows[0] ?? null;
}

function vr_resolver_vinculo(array $row): array {
    $tipo = (string)($row['tipo_objeto'] ?? '');
    $key  = trim((string)($row['objeto_id'] ?? ''));

    $out = [
        'title' => $key,
        'subtitle' => '',
        'key' => $key,
        'resolved' => false,
    ];

    if ($key === '') {
        $out['title'] = '(sin clave)';
        return $out;
    }

    if ($tipo === 'foto') {
        $rs = q(
            "SELECT nombramiento, inv
             FROM digitales
             WHERE nombramiento = ?
             LIMIT 1",
            's',
            [$key]
        );
        if ($rs) {
            $r = $rs[0];
            $out['title'] = (string)($r['nombramiento'] ?? $key);
            $inv = trim((string)($r['inv'] ?? ''));
            $out['subtitle'] = $inv !== '' ? ('Sobre: ' . $inv) : '';
            $out['resolved'] = true;
            return $out;
        }

        // Fallback: key tipo FO073550_041 -> resolver por inv + etiqueta
        if (preg_match('/^(.+?)_(\d{1,4})$/', $key, $m)) {
            $inv = trim((string)$m[1]);
            $lab = str_pad((string)(int)$m[2], 3, '0', STR_PAD_LEFT);

            $rs2 = q(
                "SELECT nombramiento, inv
                 FROM digitales
                 WHERE inv = ?
                   AND nombramiento IS NOT NULL
                   AND nombramiento <> ''
                 ORDER BY nombramiento ASC",
                's',
                [$inv]
            );

            foreach ($rs2 as $r) {
                $name = (string)($r['nombramiento'] ?? '');
                if ($name !== '' && preg_match('/_(\d{1,4})\.(jpe?g|png|tif|tiff)$/i', $name, $mm)) {
                    $lab2 = str_pad((string)(int)$mm[1], 3, '0', STR_PAD_LEFT);
                    if ($lab2 === $lab) {
                        $out['title'] = $key;
                        $out['subtitle'] = 'Sobre: ' . $inv;
                        $out['resolved'] = true;
                        return $out;
                    }
                }
            }
        }

        return $out;
    }

    if ($tipo === 'sobre') {
        $title = $key;
        $subtitle = '';

        $rs1 = q(
            "SELECT barcode
             FROM items
             WHERE barcode = ?
             LIMIT 1",
            's',
            [$key]
        );
        if ($rs1) {
            $r = $rs1[0];
            $title = (string)($r['barcode'] ?? $key);
            $subtitle = 'items.barcode';
            $out['resolved'] = true;
        } else {
            $rs2 = q(
                "SELECT inv
                 FROM digitales
                 WHERE inv = ?
                 LIMIT 1",
                's',
                [$key]
            );
            if ($rs2) {
                $r = $rs2[0];
                $title = (string)($r['inv'] ?? $key);
                $subtitle = 'digitales.inv';
                $out['resolved'] = true;
            }
        }

        $out['title'] = $title;
        $out['subtitle'] = $subtitle;
        return $out;
    }

    if ($tipo === 'coleccion') {
        $id = ctype_digit($key) ? (int)$key : 0;
        if ($id > 0) {
            $rs = q(
                "SELECT id, title
                 FROM collections_v2
                 WHERE id = ?
                 LIMIT 1",
                'i',
                [$id]
            );
            if ($rs) {
                $r = $rs[0];
                $out['title'] = (string)($r['title'] ?? ('Colección #' . $key));
                $out['subtitle'] = 'ID ' . (string)($r['id'] ?? $key);
                $out['resolved'] = true;
            }
        }
        return $out;
    }

    if ($tipo === 'expo') {
        $id = ctype_digit($key) ? (int)$key : 0;
        if ($id > 0) {
            $rs = q(
                "SELECT id, title, slug
                 FROM expo_v1
                 WHERE id = ?
                 LIMIT 1",
                'i',
                [$id]
            );
            if ($rs) {
                $r = $rs[0];
                $out['title'] = (string)($r['title'] ?? ('Expo #' . $key));
                $slug = trim((string)($r['slug'] ?? ''));
                $out['subtitle'] = $slug !== '' ? $slug : ('ID ' . (string)($r['id'] ?? $key));
                $out['resolved'] = true;
            }
        }
        return $out;
    }

    return $out;
}

function vr_collection_has_recorte(int $collectionId, int $recorteId): bool {
    $rows = q(
        "SELECT collection_id
         FROM collection_items_v2
         WHERE collection_id = ?
           AND COALESCE(item_type,'foto') = 'recorte'
           AND COALESCE(item_key,image_key) = ?
         LIMIT 1",
        'is',
        [$collectionId, (string)$recorteId]
    );
    return !empty($rows);
}

function vr_collection_next_position(int $collectionId): int {
    $rows = q(
        "SELECT COALESCE(MAX(position), 0) AS max_pos
         FROM collection_items_v2
         WHERE collection_id = ?",
        'i',
        [$collectionId]
    );
    return ((int)($rows[0]['max_pos'] ?? 0)) + 1;
}

function vr_collection_get_photo_items(int $collectionId, int $usuarioId): array {
    $col = q(
        "SELECT id
         FROM collections_v2
         WHERE id = ?
           AND created_by_user_id = ?
         LIMIT 1",
        'ii',
        [$collectionId, $usuarioId]
    );

    if (!$col) {
        return [];
    }

    $rows = q(
        "SELECT
            COALESCE(item_key, image_key) AS image_key,
            position
         FROM collection_items_v2
         WHERE collection_id = ?
           AND COALESCE(item_type, 'foto') = 'foto'
           AND COALESCE(item_key, image_key) IS NOT NULL
           AND COALESCE(item_key, image_key) <> ''
         ORDER BY position ASC, COALESCE(item_key, image_key) ASC",
        'i',
        [$collectionId]
    );

    return $rows ?: [];
}

function vr_resolve_collection_photos(int $collectionId, int $usuarioId): array {
    $items = vr_collection_get_photo_items($collectionId, $usuarioId);
    if (!$items) {
        return [];
    }

    $resolved = v2_resolve_imagekeys_to_digital($items);
    $out = [];

    foreach ($items as $it) {
        $k = trim((string)($it['image_key'] ?? ''));
        if ($k === '') {
            continue;
        }
        $r = $resolved[$k] ?? null;
        $out[] = [
            'image_key' => $k,
            'position' => (int)($it['position'] ?? 0),
            'label' => (string)($r['label'] ?? ''),
            'url' => (string)($r['url'] ?? ''),
            'ok' => !empty($r['ok']),
            'inv' => (string)($r['barcode'] ?? ''),
            'nombramiento' => (string)($r['nombramiento'] ?? ''),
        ];
    }

    return $out;
}

function vr_get_collections_with_recorte(int $recorteId, int $usuarioId): array {
    $rows = q(
        "SELECT c.id, c.title, c.created_by_user_id, ci.position
         FROM collection_items_v2 ci
         INNER JOIN collections_v2 c
           ON c.id = ci.collection_id
         WHERE COALESCE(ci.item_type,'foto') = 'recorte'
           AND COALESCE(ci.item_key,ci.image_key) = ?
           AND c.created_by_user_id = ?
         ORDER BY c.title ASC, ci.position ASC",
        'si',
        [(string)$recorteId, $usuarioId]
    );
    return $rows ?: [];
}

$rec = vr_recorte_get_owned($recorteId, $usuarioId);
if (!$rec) {
    die('Recorte no encontrado');
}

$misColecciones = v2_collections_list_my($usuarioId);

$error = '';
$okMsg = '';
$collectionError = '';
$collectionOk = '';
$linkCollectionError = '';
$linkCollectionOk = '';
$removeCollectionError = '';
$removeCollectionOk = '';
$deleteError = '';

$selectedPhotoCollectionId = 0;
$collectionPhotoItems = [];
$selectedPhotoKey = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = (string)($_POST['csrf'] ?? '');
    if (!hash_equals($csrf, $postedCsrf)) {
        die('CSRF inválido');
    }

    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'delete_link') {
        $linkId = (int)($_POST['link_id'] ?? 0);

        if ($linkId <= 0) {
            $error = 'Vínculo inválido.';
        } else {
            $owned = q(
                "SELECT rv.id
                 FROM recorte_vinculos rv
                 INNER JOIN recortes r ON r.id = rv.recorte_id
                 WHERE rv.id = ?
                   AND r.id = ?
                   AND r.usuario_id = ?
                 LIMIT 1",
                'iii',
                [$linkId, $recorteId, $usuarioId]
            );

            if (!$owned) {
                $error = 'No se pudo borrar el vínculo.';
            } else {
                q(
                    "DELETE FROM recorte_vinculos
                     WHERE id = ?
                     LIMIT 1",
                    'i',
                    [$linkId]
                );
                $okMsg = 'Vínculo eliminado.';
            }
        }
    }

    if ($action === 'remove_from_collection') {
        $collectionId = (int)($_POST['collection_id'] ?? 0);

        if ($collectionId <= 0) {
            $removeCollectionError = 'Colección inválida.';
        } else {
            $owned = q(
                "SELECT c.id
                 FROM collections_v2 c
                 WHERE c.id = ?
                   AND c.created_by_user_id = ?
                 LIMIT 1",
                'ii',
                [$collectionId, $usuarioId]
            );

            if (!$owned) {
                $removeCollectionError = 'La colección no existe o no te pertenece.';
            } else {
                q(
                    "DELETE FROM collection_items_v2
                     WHERE collection_id = ?
                       AND COALESCE(item_type,'foto') = 'recorte'
                       AND COALESCE(item_key,image_key) = ?",
                    'is',
                    [$collectionId, (string)$recorteId]
                );
                $removeCollectionOk = 'Recorte quitado de la colección.';
            }
        }
    }

    if ($action === 'delete_recorte') {
        $owned = vr_recorte_get_owned($recorteId, $usuarioId);

        if (!$owned) {
            $deleteError = 'No se pudo borrar el recorte.';
        } else {
            q(
                "DELETE FROM recorte_vinculos
                 WHERE recorte_id = ?",
                'i',
                [$recorteId]
            );

            q(
                "DELETE FROM collection_items_v2
                 WHERE COALESCE(item_type,'foto') = 'recorte'
                   AND COALESCE(item_key,image_key) = ?",
                's',
                [(string)$recorteId]
            );

            q(
                "DELETE FROM recortes
                 WHERE id = ?
                   AND usuario_id = ?
                 LIMIT 1",
                'ii',
                [$recorteId, $usuarioId]
            );

            header('Location: misrecortes.php?deleted=1');
            exit;
        }
    }

    if ($action === 'save_link') {
        $tipo = trim((string)($_POST['tipo_objeto'] ?? ''));
        $objetoId = trim((string)($_POST['objeto_id'] ?? ''));

        $tiposValidos = ['foto', 'sobre', 'coleccion', 'expo'];

        if (!in_array($tipo, $tiposValidos, true)) {
            $error = 'Tipo inválido.';
        } elseif ($objetoId === '') {
            $error = 'Seleccioná un objeto.';
        } else {
            $exists = false;

            if ($tipo === 'foto') {
                $rs = q(
                    "SELECT nombramiento
                     FROM digitales
                     WHERE nombramiento = ?
                     LIMIT 1",
                    's',
                    [$objetoId]
                );
                $exists = !empty($rs);

                if (!$exists && preg_match('/^(.+?)_(\d{1,4})$/', $objetoId, $m)) {
                    $inv = trim((string)$m[1]);
                    $lab = str_pad((string)(int)$m[2], 3, '0', STR_PAD_LEFT);

                    $rs2 = q(
                        "SELECT nombramiento
                         FROM digitales
                         WHERE inv = ?
                           AND nombramiento IS NOT NULL
                           AND nombramiento <> ''",
                        's',
                        [$inv]
                    );

                    foreach ($rs2 as $r) {
                        $name = (string)($r['nombramiento'] ?? '');
                        if ($name !== '' && preg_match('/_(\d{1,4})\.(jpe?g|png|tif|tiff)$/i', $name, $mm)) {
                            $lab2 = str_pad((string)(int)$mm[1], 3, '0', STR_PAD_LEFT);
                            if ($lab2 === $lab) {
                                $exists = true;
                                break;
                            }
                        }
                    }
                }
            } elseif ($tipo === 'sobre') {
                $rs1 = q(
                    "SELECT barcode
                     FROM items
                     WHERE barcode = ?
                     LIMIT 1",
                    's',
                    [$objetoId]
                );
                $rs2 = q(
                    "SELECT inv
                     FROM digitales
                     WHERE inv = ?
                     LIMIT 1",
                    's',
                    [$objetoId]
                );
                $exists = !empty($rs1) || !empty($rs2);
            } elseif ($tipo === 'coleccion') {
                $id = ctype_digit($objetoId) ? (int)$objetoId : 0;
                if ($id > 0) {
                    $rs = q(
                        "SELECT id
                         FROM collections_v2
                         WHERE id = ?
                         LIMIT 1",
                        'i',
                        [$id]
                    );
                    $exists = !empty($rs);
                }
            } elseif ($tipo === 'expo') {
                $id = ctype_digit($objetoId) ? (int)$objetoId : 0;
                if ($id > 0) {
                    $rs = q(
                        "SELECT id
                         FROM expo_v1
                         WHERE id = ?
                         LIMIT 1",
                        'i',
                        [$id]
                    );
                    $exists = !empty($rs);
                }
            }

            if (!$exists) {
                $error = 'El objeto seleccionado ya no existe o no pudo validarse.';
            } else {
                $dup = q(
                    "SELECT id
                     FROM recorte_vinculos
                     WHERE recorte_id = ? AND tipo_objeto = ? AND objeto_id = ?
                     LIMIT 1",
                    'iss',
                    [$recorteId, $tipo, $objetoId]
                );

                if ($dup) {
                    $error = 'Ese vínculo ya existe.';
                } else {
                    q(
                        "INSERT INTO recorte_vinculos (recorte_id, tipo_objeto, objeto_id)
                         VALUES (?, ?, ?)",
                        'iss',
                        [$recorteId, $tipo, $objetoId]
                    );
                    $okMsg = 'Vínculo guardado.';
                }
            }
        }
    }

    if ($action === 'add_to_collection') {
        $collectionId = (int)($_POST['collection_id'] ?? 0);

        if ($collectionId <= 0) {
            $collectionError = 'Elegí una colección.';
        } else {
            $collection = q(
                "SELECT id, title
                 FROM collections_v2
                 WHERE id = ?
                   AND created_by_user_id = ?
                 LIMIT 1",
                'ii',
                [$collectionId, $usuarioId]
            );

            if (!$collection) {
                $collectionError = 'La colección no existe o no te pertenece.';
            } elseif (vr_collection_has_recorte($collectionId, $recorteId)) {
                $collectionError = 'Ese recorte ya está en la colección.';
            } else {
                $position = vr_collection_next_position($collectionId);

                q(
                    "INSERT INTO collection_items_v2 (collection_id, item_type, item_key, position)
                     VALUES (?, 'recorte', ?, ?)",
                    'isi',
                    [$collectionId, (string)$recorteId, $position]
                );

                $collectionOk = 'Recorte agregado a la colección.';
            }
        }
    }

    if ($action === 'load_collection_photos' || $action === 'link_collection_photo') {
        $selectedPhotoCollectionId = (int)($_POST['photo_collection_id'] ?? 0);

        if ($selectedPhotoCollectionId > 0) {
            $collectionPhotoItems = vr_resolve_collection_photos($selectedPhotoCollectionId, $usuarioId);
        }
    }

    if ($action === 'link_collection_photo') {
        $selectedPhotoKey = trim((string)($_POST['selected_photo_key'] ?? ''));

        if ($selectedPhotoCollectionId <= 0) {
            $linkCollectionError = 'Elegí una colección.';
        } elseif ($selectedPhotoKey === '') {
            $linkCollectionError = 'Elegí una foto de la colección.';
        } else {
            $existsInCollection = false;
            foreach ($collectionPhotoItems as $pi) {
                if ((string)($pi['image_key'] ?? '') === $selectedPhotoKey) {
                    $existsInCollection = true;
                    break;
                }
            }

            if (!$existsInCollection) {
                $linkCollectionError = 'La foto seleccionada no pertenece a esa colección o no pudo resolverse.';
            } else {
                $dup = q(
                    "SELECT id
                     FROM recorte_vinculos
                     WHERE recorte_id = ?
                       AND tipo_objeto = 'foto'
                       AND objeto_id = ?
                     LIMIT 1",
                    'is',
                    [$recorteId, $selectedPhotoKey]
                );

                if ($dup) {
                    $linkCollectionError = 'Ese vínculo con foto ya existe.';
                } else {
                    q(
                        "INSERT INTO recorte_vinculos (recorte_id, tipo_objeto, objeto_id)
                         VALUES (?, 'foto', ?)",
                        'is',
                        [$recorteId, $selectedPhotoKey]
                    );
                    $linkCollectionOk = 'Vínculo con foto guardado.';
                }
            }
        }
    }
}

if ($selectedPhotoCollectionId > 0 && !$collectionPhotoItems) {
    $collectionPhotoItems = vr_resolve_collection_photos($selectedPhotoCollectionId, $usuarioId);
}

$vinculos = q(
    "SELECT id, recorte_id, tipo_objeto, objeto_id, creado
     FROM recorte_vinculos
     WHERE recorte_id = ?
     ORDER BY id DESC",
    'i',
    [$recorteId]
);

$coleccionesConRecorte = vr_get_collections_with_recorte($recorteId, $usuarioId);

$pageTitle = 'Vincular recorte';
require_once __DIR__ . '/inc/header.php';
?>

<style>
.vr-wrap{
  max-width: 1240px;
  margin: 18px auto;
  padding: 0 14px 24px;
}
.vr-grid{
  display:grid;
  grid-template-columns: minmax(320px, 460px) minmax(360px, 1fr);
  gap: 16px;
}
@media (max-width: 980px){
  .vr-grid{ grid-template-columns: 1fr; }
}
.vr-card{
  background: var(--afdc-card, #fffdf8);
  color: var(--afdc-text, #2f2418);
  border: 1px solid var(--afdc-border, rgba(0,0,0,.12));
  border-radius: 16px;
  padding: 18px;
  box-shadow: 0 8px 24px rgba(0,0,0,.08);
}
.vr-title{
  margin: 0 0 8px;
  color: var(--afdc-text, #2f2418);
}
.vr-sub{
  color: var(--afdc-muted, #6e5d49);
  font-size: 13px;
  margin-bottom: 14px;
}
.vr-preview{
  border: 1px solid var(--afdc-border, rgba(0,0,0,.12));
  border-radius: 12px;
  background: rgba(0,0,0,.03);
  overflow: hidden;
}
.vr-preview img{
  display:block;
  width:100%;
  height:auto;
}
.vr-meta{
  margin-top: 12px;
  display:grid;
  grid-template-columns: repeat(2, minmax(0,1fr));
  gap: 8px;
}
.vr-pill{
  padding: 8px 10px;
  border-radius: 10px;
  border: 1px solid var(--afdc-border, rgba(0,0,0,.12));
  background: rgba(255,255,255,.35);
  font-size: 13px;
}
.vr-section-title{
  margin: 0 0 10px;
  font-size: 16px;
  color: var(--afdc-text, #2f2418);
}
.vr-section{
  margin-top: 18px;
  padding-top: 16px;
  border-top: 1px solid var(--afdc-border, rgba(0,0,0,.10));
}
.vr-list{
  display:flex;
  flex-direction:column;
  gap:10px;
  margin-bottom: 18px;
}
.vr-item{
  padding: 12px;
  border-radius: 12px;
  border: 1px solid var(--afdc-border, rgba(0,0,0,.12));
  background: rgba(255,255,255,.32);
}
.vr-item-top{
  display:flex;
  justify-content:space-between;
  gap:10px;
  align-items:flex-start;
}
.vr-item-type{
  font-size: 12px;
  color: var(--afdc-muted, #6e5d49);
  text-transform: uppercase;
  letter-spacing: .04em;
}
.vr-item-title{
  font-weight: 600;
  color: var(--afdc-text, #2f2418);
}
.vr-item-sub{
  margin-top: 4px;
  color: var(--afdc-muted, #6e5d49);
  font-size: 13px;
}
.vr-item-key{
  margin-top: 6px;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 12px;
  opacity: .85;
}
.vr-empty{
  padding: 12px;
  border: 1px dashed var(--afdc-border, rgba(0,0,0,.16));
  border-radius: 12px;
  color: var(--afdc-muted, #6e5d49);
}
.vr-label{
  display:block;
  margin:12px 0 6px;
  font-size:14px;
  color: var(--afdc-text, #2f2418);
}
.vr-input,
.vr-select{
  width:100%;
  padding:10px 12px;
  border-radius:10px;
  border:1px solid var(--afdc-border, rgba(0,0,0,.15));
  background: var(--afdc-input-bg, #fff);
  color: var(--afdc-text, #2f2418);
  box-sizing:border-box;
  outline:none;
}
.vr-input::placeholder{
  color: var(--afdc-muted, #6e5d49);
}
.vr-input:focus,
.vr-select:focus{
  border-color: var(--afdc-accent, #b88c2a);
  box-shadow: 0 0 0 3px rgba(184,140,42,.15);
}
.vr-inline{
  display:grid;
  grid-template-columns: 1fr auto;
  gap: 10px;
  align-items:end;
}
@media (max-width: 640px){
  .vr-inline{ grid-template-columns: 1fr; }
}
.vr-btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-height:40px;
  padding:9px 14px;
  border-radius:10px;
  border:1px solid var(--afdc-border, rgba(0,0,0,.12));
  background: var(--afdc-btn-bg, rgba(255,255,255,.7));
  color: var(--afdc-text, #2f2418);
  text-decoration:none;
  cursor:pointer;
  transition: background .15s ease, border-color .15s ease, transform .05s ease;
}
.vr-btn:hover{
  background: var(--afdc-btn-hover, #fff);
  color: var(--afdc-text, #2f2418);
  text-decoration:none;
}
.vr-btn:active{ transform: translateY(1px); }
.vr-btn[disabled]{
  opacity:.55;
  cursor:not-allowed;
}
.vr-btn-danger{
  background: rgba(140, 30, 30, .08);
  border-color: rgba(140, 30, 30, .18);
  color: #8b1e1e;
}
.vr-btn-danger:hover{
  background: rgba(140, 30, 30, .14);
  color: #8b1e1e;
}
.vr-btn-small{
  min-height: 32px;
  padding: 6px 10px;
  font-size: 13px;
}
.vr-actions{
  margin-top:16px;
  display:flex;
  gap:10px;
  flex-wrap:wrap;
}
.vr-error,
.vr-ok,
.vr-help{
  margin-top: 10px;
  padding: 10px 12px;
  border-radius: 10px;
  font-size: 14px;
}
.vr-error{
  border: 1px solid rgba(140, 30, 30, .18);
  background: rgba(140, 30, 30, .08);
  color: #8b1e1e;
}
.vr-ok{
  border: 1px solid rgba(40, 120, 40, .18);
  background: rgba(40, 120, 40, .08);
  color: #256b25;
}
.vr-help{
  border: 1px solid var(--afdc-border, rgba(0,0,0,.12));
  background: rgba(255,255,255,.22);
  color: var(--afdc-muted, #6e5d49);
}
.vr-results{
  margin-top: 12px;
  display:flex;
  flex-direction:column;
  gap:8px;
}
.vr-result{
  display:block;
  width:100%;
  text-align:left;
  padding: 12px;
  border-radius: 12px;
  border: 1px solid var(--afdc-border, rgba(0,0,0,.12));
  background: rgba(255,255,255,.32);
  color: var(--afdc-text, #2f2418);
  cursor:pointer;
}
.vr-result:hover{
  background: rgba(255,255,255,.5);
}
.vr-result.is-selected{
  border-color: var(--afdc-accent, #b88c2a);
  box-shadow: 0 0 0 2px rgba(184,140,42,.15) inset;
}
.vr-result-title{
  font-weight: 600;
}
.vr-result-sub{
  margin-top: 4px;
  font-size: 13px;
  color: var(--afdc-muted, #6e5d49);
}
.vr-selected{
  margin-top: 12px;
  padding: 12px;
  border-radius: 12px;
  border: 1px solid var(--afdc-border, rgba(0,0,0,.12));
  background: rgba(255,255,255,.32);
}
.vr-selected strong{
  display:block;
  margin-bottom: 4px;
}
.vr-photo-grid{
  margin-top: 12px;
  display:grid;
  grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
  gap: 10px;
}
.vr-photo-card{
  border: 1px solid var(--afdc-border, rgba(0,0,0,.12));
  border-radius: 12px;
  background: rgba(255,255,255,.32);
  overflow: hidden;
  cursor: pointer;
}
.vr-photo-card.is-selected{
  border-color: var(--afdc-accent, #b88c2a);
  box-shadow: 0 0 0 2px rgba(184,140,42,.15) inset;
}
.vr-photo-thumb{
  aspect-ratio: 1 / 1;
  background: rgba(0,0,0,.05);
  display:flex;
  align-items:center;
  justify-content:center;
  overflow:hidden;
}
.vr-photo-thumb img{
  width:100%;
  height:100%;
  object-fit:cover;
  display:block;
}
.vr-photo-body{
  padding: 8px;
}
.vr-photo-title{
  font-size: 12px;
  font-weight: 600;
  line-height: 1.25;
  word-break: break-word;
}
.vr-photo-sub{
  margin-top: 4px;
  font-size: 11px;
  color: var(--afdc-muted, #6e5d49);
  line-height: 1.25;
  word-break: break-word;
}
.vr-side-actions{
  margin-top: 18px;
  display:flex;
  flex-direction:column;
  gap:10px;
}
.vr-mini-form{
  display:inline;
}
</style>

<div class="vr-wrap">
  <div class="vr-grid">
    <div class="vr-card">
      <h1 class="vr-title">Recorte #<?= (int)$recorteId ?></h1>
      <div class="vr-sub">Usuario: <?= vr_h($usuarioId) ?></div>

      <div class="vr-preview">
        <img
          src="api/recorte_render.php?id=<?= (int)$recorteId ?>&modo=crop&maxw=900&q=88"
          alt="Preview del recorte #<?= (int)$recorteId ?>"
          loading="eager"
        >
      </div>

      <div class="vr-meta">
        <div class="vr-pill">Barcode: <?= vr_h($rec['barcode'] ?? '') ?></div>
        <div class="vr-pill">Origen: <?= vr_h($rec['recorte_origen_id'] ?? '') ?></div>
        <div class="vr-pill">Pág. izq: <?= vr_h($rec['pag_izq'] ?? '') ?></div>
        <div class="vr-pill">Pág. der: <?= vr_h($rec['pag_der'] ?? '') ?></div>
      </div>

      <div class="vr-side-actions">
        <?php if ($deleteError !== ''): ?>
          <div class="vr-error"><?= vr_h($deleteError) ?></div>
        <?php endif; ?>

        <form method="post" onsubmit="return confirm('Se va a borrar el recorte, sus vínculos y su presencia en colecciones. ¿Continuar?');">
          <input type="hidden" name="csrf" value="<?= vr_h($csrf) ?>">
          <input type="hidden" name="action" value="delete_recorte">
          <button class="vr-btn vr-btn-danger" type="submit">Borrar recorte</button>
        </form>
      </div>
    </div>

    <div class="vr-card">
      <h2 class="vr-section-title">Agregar a colección</h2>

      <?php if (!$misColecciones): ?>
        <div class="vr-empty">No tenés colecciones propias disponibles.</div>
      <?php else: ?>
        <form method="post" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= vr_h($csrf) ?>">
          <input type="hidden" name="action" value="add_to_collection">

          <label class="vr-label" for="collection_id">Mis colecciones</label>
          <select class="vr-select" name="collection_id" id="collection_id" required>
            <option value="">-- seleccionar colección --</option>
            <?php foreach ($misColecciones as $mc): ?>
              <option value="<?= (int)$mc['id'] ?>">
                <?= vr_h((string)$mc['title']) ?> (<?= (int)($mc['count_items'] ?? 0) ?> items)
              </option>
            <?php endforeach; ?>
          </select>

          <div class="vr-help">
            Esto agrega el recorte como item de la colección. No lo vincula todavía a una foto puntual.
          </div>

          <?php if ($collectionError !== ''): ?>
            <div class="vr-error"><?= vr_h($collectionError) ?></div>
          <?php endif; ?>

          <?php if ($collectionOk !== ''): ?>
            <div class="vr-ok"><?= vr_h($collectionOk) ?></div>
          <?php endif; ?>

          <div class="vr-actions">
            <button class="vr-btn" type="submit">Agregar a colección</button>
          </div>
        </form>
      <?php endif; ?>

      <div class="vr-section">
        <h2 class="vr-section-title">Colecciones que contienen este recorte</h2>

        <?php if (!$coleccionesConRecorte): ?>
          <div class="vr-empty">Este recorte no está agregado a ninguna de tus colecciones.</div>
        <?php else: ?>
          <div class="vr-list">
            <?php foreach ($coleccionesConRecorte as $cc): ?>
              <div class="vr-item">
                <div class="vr-item-top">
                  <div>
                    <div class="vr-item-type">Colección</div>
                    <div class="vr-item-title"><?= vr_h((string)$cc['title']) ?></div>
                  </div>
                  <div class="vr-item-type">Posición <?= (int)($cc['position'] ?? 0) ?></div>
                </div>

                <div class="vr-item-sub">ID <?= (int)$cc['id'] ?></div>

                <div class="vr-actions">
                  <form class="vr-mini-form" method="post" onsubmit="return confirm('¿Quitar este recorte de la colección?');">
                    <input type="hidden" name="csrf" value="<?= vr_h($csrf) ?>">
                    <input type="hidden" name="action" value="remove_from_collection">
                    <input type="hidden" name="collection_id" value="<?= (int)$cc['id'] ?>">
                    <button class="vr-btn vr-btn-small vr-btn-danger" type="submit">Quitar de colección</button>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if ($removeCollectionError !== ''): ?>
          <div class="vr-error"><?= vr_h($removeCollectionError) ?></div>
        <?php endif; ?>

        <?php if ($removeCollectionOk !== ''): ?>
          <div class="vr-ok"><?= vr_h($removeCollectionOk) ?></div>
        <?php endif; ?>
      </div>

      <div class="vr-section">
        <h2 class="vr-section-title">Vincular a foto de una colección</h2>

        <?php if (!$misColecciones): ?>
          <div class="vr-empty">No tenés colecciones para elegir fotos.</div>
        <?php else: ?>
          <form method="post" id="vr-link-collection-photo-form" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= vr_h($csrf) ?>">
            <input type="hidden" name="action" id="vr-photo-action" value="load_collection_photos">
            <input type="hidden" name="selected_photo_key" id="selected_photo_key" value="<?= vr_h($selectedPhotoKey) ?>">

            <label class="vr-label" for="photo_collection_id">Colección</label>
            <select class="vr-select" name="photo_collection_id" id="photo_collection_id" required>
              <option value="">-- seleccionar colección --</option>
              <?php foreach ($misColecciones as $mc): ?>
                <option value="<?= (int)$mc['id'] ?>" <?= $selectedPhotoCollectionId === (int)$mc['id'] ? 'selected' : '' ?>>
                  <?= vr_h((string)$mc['title']) ?> (<?= (int)($mc['count_items'] ?? 0) ?> items)
                </option>
              <?php endforeach; ?>
            </select>

            <div class="vr-help">
              Primero cargás las fotos de una colección propia. Después elegís una y guardás el vínculo recorte ↔ foto.
            </div>

            <div class="vr-actions">
              <button class="vr-btn" type="submit" onclick="document.getElementById('vr-photo-action').value='load_collection_photos';">Cargar fotos</button>
            </div>

            <?php if ($linkCollectionError !== ''): ?>
              <div class="vr-error"><?= vr_h($linkCollectionError) ?></div>
            <?php endif; ?>

            <?php if ($linkCollectionOk !== ''): ?>
              <div class="vr-ok"><?= vr_h($linkCollectionOk) ?></div>
            <?php endif; ?>

            <?php if ($selectedPhotoCollectionId > 0): ?>
              <div class="vr-section" style="margin-top:14px;">
                <h3 class="vr-section-title" style="font-size:15px;">Fotos de la colección</h3>

                <?php if (!$collectionPhotoItems): ?>
                  <div class="vr-empty">Esta colección no tiene fotos resolubles.</div>
                <?php else: ?>
                  <div class="vr-photo-grid" id="vr-photo-grid">
                    <?php foreach ($collectionPhotoItems as $pi): ?>
                      <?php
                        $k = (string)($pi['image_key'] ?? '');
                        $isSelected = ($selectedPhotoKey !== '' && $selectedPhotoKey === $k);
                      ?>
                      <div
                        class="vr-photo-card<?= $isSelected ? ' is-selected' : '' ?>"
                        data-photo-key="<?= vr_h($k) ?>"
                        data-photo-title="<?= vr_h((string)($pi['nombramiento'] !== '' ? $pi['nombramiento'] : $k)) ?>"
                        tabindex="0"
                      >
                        <div class="vr-photo-thumb">
                          <?php if (!empty($pi['url'])): ?>
                            <img src="<?= vr_h((string)$pi['url']) ?>" alt="<?= vr_h($k) ?>" loading="lazy">
                          <?php else: ?>
                            <div class="vr-empty" style="margin:6px;">Sin miniatura</div>
                          <?php endif; ?>
                        </div>
                        <div class="vr-photo-body">
                          <div class="vr-photo-title"><?= vr_h($k) ?></div>
                          <?php if (!empty($pi['inv'])): ?>
                            <div class="vr-photo-sub">Sobre: <?= vr_h((string)$pi['inv']) ?></div>
                          <?php endif; ?>
                          <?php if (!empty($pi['label'])): ?>
                            <div class="vr-photo-sub">Etiqueta: <?= vr_h((string)$pi['label']) ?></div>
                          <?php endif; ?>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>

                  <div class="vr-selected" id="vr-photo-selected" <?= $selectedPhotoKey !== '' ? '' : 'hidden' ?>>
                    <?php if ($selectedPhotoKey !== ''): ?>
                      <strong>Foto seleccionada</strong>
                      <div><?= vr_h($selectedPhotoKey) ?></div>
                    <?php endif; ?>
                  </div>

                  <div class="vr-actions">
                    <button
                      class="vr-btn"
                      type="submit"
                      id="vr-save-photo-link-btn"
                      <?= $selectedPhotoKey !== '' ? '' : 'disabled' ?>
                      onclick="document.getElementById('vr-photo-action').value='link_collection_photo';"
                    >Guardar vínculo con foto</button>
                  </div>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </form>
        <?php endif; ?>
      </div>

      <div class="vr-section">
        <h2 class="vr-section-title">Vínculos existentes</h2>

        <?php if (!$vinculos): ?>
          <div class="vr-empty">Este recorte todavía no tiene vínculos.</div>
        <?php else: ?>
          <div class="vr-list">
            <?php foreach ($vinculos as $v): ?>
              <?php $res = vr_resolver_vinculo($v); ?>
              <div class="vr-item">
                <div class="vr-item-top">
                  <div>
                    <div class="vr-item-type"><?= vr_h(vr_tipo_label((string)$v['tipo_objeto'])) ?></div>
                    <div class="vr-item-title"><?= vr_h($res['title']) ?></div>
                  </div>
                  <div class="vr-item-type">
                    <?= !empty($res['resolved']) ? 'resuelto' : 'sin resolver' ?>
                  </div>
                </div>

                <?php if ($res['subtitle'] !== ''): ?>
                  <div class="vr-item-sub"><?= vr_h($res['subtitle']) ?></div>
                <?php endif; ?>

                <div class="vr-item-key">Clave: <?= vr_h($res['key']) ?></div>

                <div class="vr-actions">
                  <form class="vr-mini-form" method="post" onsubmit="return confirm('¿Eliminar este vínculo?');">
                    <input type="hidden" name="csrf" value="<?= vr_h($csrf) ?>">
                    <input type="hidden" name="action" value="delete_link">
                    <input type="hidden" name="link_id" value="<?= (int)$v['id'] ?>">
                    <button class="vr-btn vr-btn-small vr-btn-danger" type="submit">Quitar vínculo</button>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
          <div class="vr-error"><?= vr_h($error) ?></div>
        <?php endif; ?>

        <?php if ($okMsg !== ''): ?>
          <div class="vr-ok"><?= vr_h($okMsg) ?></div>
        <?php endif; ?>
      </div>

      <div class="vr-section">
        <h2 class="vr-section-title">Agregar vínculo</h2>

        <form method="post" id="vr-form" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= vr_h($csrf) ?>">
          <input type="hidden" name="action" value="save_link">

          <label class="vr-label" for="tipo_objeto">Tipo de objeto</label>
          <select class="vr-select" name="tipo_objeto" id="tipo_objeto" required>
            <option value="">-- seleccionar --</option>
            <option value="foto">Foto</option>
            <option value="sobre">Sobre</option>
            <option value="coleccion">Colección</option>
            <option value="expo">Exposición</option>
          </select>

          <div class="vr-help" id="vr-help">
            Elegí un tipo y buscá un objeto para vincular.
          </div>

          <div class="vr-inline">
            <div>
              <label class="vr-label" for="vr-q">Buscar objeto</label>
              <input
                class="vr-input"
                type="text"
                id="vr-q"
                placeholder="Escribí para buscar"
              >
            </div>
            <button class="vr-btn" type="button" id="vr-search-btn">Buscar</button>
          </div>

          <input type="hidden" name="objeto_id" id="objeto_id" value="">

          <div class="vr-results" id="vr-results"></div>
          <div class="vr-selected" id="vr-selected" hidden></div>

          <div class="vr-actions">
            <button class="vr-btn" type="submit" id="vr-submit-btn" disabled>Guardar vínculo</button>
            <a class="vr-btn" href="misrecortes.php">Volver</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  const tipoEl = document.getElementById('tipo_objeto');
  const qEl = document.getElementById('vr-q');
  const resultsEl = document.getElementById('vr-results');
  const selectedEl = document.getElementById('vr-selected');
  const hiddenEl = document.getElementById('objeto_id');
  const searchBtn = document.getElementById('vr-search-btn');
  const submitBtn = document.getElementById('vr-submit-btn');
  const helpEl = document.getElementById('vr-help');

  function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, function (m) {
      return ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
      })[m];
    });
  }

  function resetSelection() {
    hiddenEl.value = '';
    selectedEl.hidden = true;
    selectedEl.innerHTML = '';
    submitBtn.disabled = true;
    resultsEl.querySelectorAll('.vr-result').forEach(el => el.classList.remove('is-selected'));
  }

  function getMinChars(tipo) {
    if (tipo === 'foto' || tipo === 'sobre') return 3;
    if (tipo === 'coleccion' || tipo === 'expo') return 2;
    return 999;
  }

  function updateHelp() {
    const tipo = tipoEl.value;
    let txt = 'Elegí un tipo y buscá un objeto para vincular.';

    if (tipo === 'foto') {
      txt = 'Foto: buscá por nombramiento. También puede ayudar el inv.';
      qEl.placeholder = 'Ej: BNA_F0001764_001';
    } else if (tipo === 'sobre') {
      txt = 'Sobre: buscá por barcode o inv.';
      qEl.placeholder = 'Ej: FO001764';
    } else if (tipo === 'coleccion') {
      txt = 'Colección: buscá por título.';
      qEl.placeholder = 'Ej: Boxeo';
    } else if (tipo === 'expo') {
      txt = 'Exposición: buscá por título o slug.';
      qEl.placeholder = 'Ej: Futbolistas';
    } else {
      qEl.placeholder = 'Escribí para buscar';
    }

    helpEl.textContent = txt;
  }

  function renderResults(items) {
    resultsEl.innerHTML = '';

    if (!items.length) {
      resultsEl.innerHTML = '<div class="vr-empty">Sin resultados.</div>';
      return;
    }

    items.forEach(item => {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'vr-result';
      btn.dataset.key = item.key || '';
      btn.dataset.title = item.title || '';
      btn.dataset.subtitle = item.subtitle || '';
      btn.innerHTML =
        '<div class="vr-result-title">' + escapeHtml(item.title || '') + '</div>' +
        (item.subtitle ? '<div class="vr-result-sub">' + escapeHtml(item.subtitle) + '</div>' : '');

      btn.addEventListener('click', function () {
        resultsEl.querySelectorAll('.vr-result').forEach(el => el.classList.remove('is-selected'));
        btn.classList.add('is-selected');
        hiddenEl.value = btn.dataset.key || '';
        selectedEl.hidden = false;
        selectedEl.innerHTML =
          '<strong>Objeto seleccionado</strong>' +
          '<div>' + escapeHtml(btn.dataset.title || '') + '</div>' +
          (btn.dataset.subtitle ? '<div class="vr-result-sub">' + escapeHtml(btn.dataset.subtitle) + '</div>' : '') +
          '<div class="vr-item-key">Clave: ' + escapeHtml(btn.dataset.key || '') + '</div>';
        submitBtn.disabled = !hiddenEl.value;
      });

      resultsEl.appendChild(btn);
    });
  }

  async function buscar() {
    const tipo = tipoEl.value.trim();
    const q = qEl.value.trim();

    resetSelection();
    resultsEl.innerHTML = '';

    if (!tipo) {
      resultsEl.innerHTML = '<div class="vr-empty">Elegí primero un tipo.</div>';
      return;
    }

    const minChars = getMinChars(tipo);
    if (q.length < minChars) {
      resultsEl.innerHTML = '<div class="vr-empty">Ingresá al menos ' + minChars + ' caracteres.</div>';
      return;
    }

    searchBtn.disabled = true;
    searchBtn.textContent = 'Buscando...';

    try {
      const url = new URL('api/buscar_objeto_vinculo.php', window.location.href);
      url.searchParams.set('tipo', tipo);
      url.searchParams.set('q', q);

      const res = await fetch(url.toString(), {
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        },
        credentials: 'same-origin'
      });

      const data = await res.json();

      if (!data || !data.ok) {
        resultsEl.innerHTML = '<div class="vr-empty">' + escapeHtml((data && data.error) ? data.error : 'No se pudo realizar la búsqueda.') + '</div>';
        return;
      }

      renderResults(Array.isArray(data.items) ? data.items : []);
    } catch (err) {
      resultsEl.innerHTML = '<div class="vr-empty">Error al buscar.</div>';
    } finally {
      searchBtn.disabled = false;
      searchBtn.textContent = 'Buscar';
    }
  }

  tipoEl.addEventListener('change', function () {
    updateHelp();
    resetSelection();
    resultsEl.innerHTML = '';
  });

  qEl.addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter') {
      ev.preventDefault();
      buscar();
    }
  });

  searchBtn.addEventListener('click', buscar);

  updateHelp();
})();

(function () {
  const grid = document.getElementById('vr-photo-grid');
  const selectedInput = document.getElementById('selected_photo_key');
  const selectedBox = document.getElementById('vr-photo-selected');
  const saveBtn = document.getElementById('vr-save-photo-link-btn');

  if (!grid || !selectedInput || !selectedBox || !saveBtn) return;

  function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, function (m) {
      return ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
      })[m];
    });
  }

  function applySelection(card) {
    grid.querySelectorAll('.vr-photo-card').forEach(el => el.classList.remove('is-selected'));
    card.classList.add('is-selected');

    const key = card.dataset.photoKey || '';
    const title = card.dataset.photoTitle || key;

    selectedInput.value = key;
    selectedBox.hidden = false;
    selectedBox.innerHTML =
      '<strong>Foto seleccionada</strong>' +
      '<div>' + escapeHtml(title) + '</div>' +
      '<div class="vr-item-key">Clave: ' + escapeHtml(key) + '</div>';

    saveBtn.disabled = !key;
  }

  grid.querySelectorAll('.vr-photo-card').forEach(card => {
    card.addEventListener('click', function () {
      applySelection(card);
    });

    card.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' || ev.key === ' ') {
        ev.preventDefault();
        applySelection(card);
      }
    });
  });
})();
</script>

<?php require_once __DIR__ . '/inc/footer.php'; ?>