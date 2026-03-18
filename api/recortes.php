<?php
declare(strict_types=1);

// Bootstrap (portable)
$bootstrap = __DIR__ . '/../inc/bootstrap.php';
if (!is_file($bootstrap)) {
    $bootstrap = __DIR__ . '/inc/bootstrap.php';
}
require_once $bootstrap;

// Auth v2 (portable)
$auth = __DIR__ . '/../inc/auth_v2.php';
if (!is_file($auth)) {
    $auth = __DIR__ . '/inc/auth_v2.php';
}
require_once $auth;

afdc_v2_session_start();
$u = afdc_v2_current_user();

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok' => false,
        'msg' => 'Method not allowed'
    ]);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'msg' => 'Invalid JSON'
    ]);
    exit;
}

$usuarioId = (int)($u['id'] ?? 0);
if ($usuarioId <= 0) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'msg' => 'Usuario no autenticado'
    ]);
    exit;
}

function recorte_str(array $data, string $key): string {
    return trim((string)($data[$key] ?? ''));
}

function recorte_float(array $data, string $key, float $default = -1): float {
    if (!isset($data[$key]) || $data[$key] === '') {
        return $default;
    }
    return (float)$data[$key];
}

function recorte_nullable_str(array $data, string $key): ?string {
    $v = trim((string)($data[$key] ?? ''));
    return $v === '' ? null : $v;
}

function recorte_clamp01(float $v): float {
    if ($v < 0) return 0.0;
    if ($v > 1) return 1.0;
    return $v;
}

$recorteId    = (int)($data['recorte_id'] ?? 0);

$barcode      = recorte_str($data, 'barcode');
$barcodeIzq   = recorte_nullable_str($data, 'barcode_izq');
$barcodeDer   = recorte_nullable_str($data, 'barcode_der');
$pagIzqRaw    = recorte_nullable_str($data, 'pag_izq');
$pagDerRaw    = recorte_nullable_str($data, 'pag_der');
$fechaIso     = recorte_nullable_str($data, 'fechaIso');
$ed           = recorte_nullable_str($data, 'ed');
$tipo         = recorte_str($data, 'tipo');
$recortadoDe  = recorte_str($data, 'recortadoDe');

$xval  = recorte_float($data, 'xval');
$yval  = recorte_float($data, 'yval');
$ancho = recorte_float($data, 'ancho');
$alto  = recorte_float($data, 'alto');

// Validaciones base
$tiposValidos = ['simple_izq', 'simple_der', 'doble'];
if ($tipo === '') {
    $tipo = 'simple_izq';
}
if (!in_array($tipo, $tiposValidos, true)) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'msg' => 'Tipo de recorte inválido'
    ]);
    exit;
}

if ($barcode === '') {
    // fallback razonable
    if ($tipo === 'simple_der' && $barcodeDer !== null) {
        $barcode = $barcodeDer;
    } elseif ($barcodeIzq !== null) {
        $barcode = $barcodeIzq;
    } elseif ($barcodeDer !== null) {
        $barcode = $barcodeDer;
    }
}

if ($barcode === '' || $recortadoDe === '') {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'msg' => 'Faltan barcode o recortadoDe'
    ]);
    exit;
}

if ($xval < 0 || $yval < 0 || $ancho <= 0 || $alto <= 0) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'msg' => 'Coordenadas inválidas'
    ]);
    exit;
}

// Normalizamos al rango 0..1
$xval  = recorte_clamp01($xval);
$yval  = recorte_clamp01($yval);
$ancho = recorte_clamp01($ancho);
$alto  = recorte_clamp01($alto);

if (($xval + $ancho) > 1) {
    $ancho = 1 - $xval;
}
if (($yval + $alto) > 1) {
    $alto = 1 - $yval;
}

if ($ancho <= 0 || $alto <= 0) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'msg' => 'El recorte quedó fuera de rango'
    ]);
    exit;
}

// Páginas: si vienen como "001", "02", etc., las guardamos como int si hay contenido
$pagIzq = ($pagIzqRaw !== null && preg_match('/\d+/', $pagIzqRaw))
    ? (int)preg_replace('/\D+/', '', $pagIzqRaw)
    : null;

$pagDer = ($pagDerRaw !== null && preg_match('/\d+/', $pagDerRaw))
    ? (int)preg_replace('/\D+/', '', $pagDerRaw)
    : null;

// Reglas mínimas por tipo
if ($tipo === 'simple_izq' && $barcodeIzq === null && $barcode !== '') {
    $barcodeIzq = $barcode;
}
if ($tipo === 'simple_der' && $barcodeDer === null && $barcode !== '') {
    $barcodeDer = $barcode;
}
if ($tipo === 'doble' && $barcodeIzq === null && $barcodeDer === null) {
    http_response_code(422);
    echo json_encode([
        'ok' => false,
        'msg' => 'Un recorte doble necesita al menos una referencia de página'
    ]);
    exit;
}

try {
    if ($recorteId > 0) {
        $existente = q(
            "SELECT id
             FROM recortes
             WHERE id = ? AND usuario_id = ?
             LIMIT 1",
            'ii',
            [$recorteId, $usuarioId]
        );

        if (!$existente) {
            http_response_code(404);
            echo json_encode([
                'ok' => false,
                'msg' => 'Recorte no encontrado'
            ]);
            exit;
        }

        q(
            "UPDATE recortes
             SET barcode=?,
                 barcode_izq=?,
                 barcode_der=?,
                 pag_izq=?,
                 pag_der=?,
                 fechaIso=?,
                 ed=?,
                 tipo=?,
                 recortadoDe=?,
                 xval=?,
                 yval=?,
                 alto=?,
                 ancho=?
             WHERE id=? AND usuario_id=?",
            'sssisssssddddii',
            [
                $barcode,
                $barcodeIzq,
                $barcodeDer,
                $pagIzq,
                $pagDer,
                $fechaIso,
                $ed,
                $tipo,
                $recortadoDe,
                $xval,
                $yval,
                $alto,
                $ancho,
                $recorteId,
                $usuarioId
            ]
        );

        echo json_encode([
            'ok' => true,
            'msg' => 'Recorte actualizado',
            'id' => $recorteId
        ]);
        exit;
    }

    q(
        "INSERT INTO recortes (
            barcode,
            barcode_izq,
            barcode_der,
            pag_izq,
            pag_der,
            fechaIso,
            ed,
            tipo,
            recortadoDe,
            xval,
            yval,
            alto,
            ancho,
            usuario_id
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
        'sssisssssddddi',
        [
            $barcode,
            $barcodeIzq,
            $barcodeDer,
            $pagIzq,
            $pagDer,
            $fechaIso,
            $ed,
            $tipo,
            $recortadoDe,
            $xval,
            $yval,
            $alto,
            $ancho,
            $usuarioId
        ]
    );

    $idRow = q("SELECT LAST_INSERT_ID() AS id");
    $nuevoId = (int)($idRow[0]['id'] ?? 0);

    echo json_encode([
        'ok' => true,
        'msg' => 'Recorte guardado',
        'id' => $nuevoId
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'msg' => $e->getMessage()
    ]);
}