<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/auth_v2.php';

afdc_v2_session_start();

$u = afdc_v2_current_user();
if (!$u) {
    die('Acceso no autorizado');
}

$usuario = (string)($u['username'] ?? '');
if ($usuario === '') {
    die('Usuario inválido');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    die('Recorte inválido');
}

$rows = q(
    "SELECT * FROM recortes WHERE id = ? AND publico = 1 LIMIT 1",
    'i',
    [$id]
);

if (!$rows) {
    die('Recorte no disponible');
}

q(
    "INSERT INTO recortes (
        barcode,
        barcode_izq,
        barcode_der,
        pag_izq,
        pag_der,
        fechalso,
        ed,
        tipo,
        recortadoDe,
        xval,
        yval,
        ancho,
        alto,
        usuario,
        recorte_origen_id
    )
    SELECT
        barcode,
        barcode_izq,
        barcode_der,
        pag_izq,
        pag_der,
        fechalso,
        ed,
        tipo,
        recortadoDe,
        xval,
        yval,
        ancho,
        alto,
        ?,
        id
    FROM recortes
    WHERE id = ? AND publico = 1",
    'si',
    [$usuario, $id]
);

header('Location: misrecortes.php');
exit;