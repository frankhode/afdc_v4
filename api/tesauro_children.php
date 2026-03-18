<?php
// api/tesauro_children.php
// Devuelve hijos inmediatos de un término del tesauro.
// Response: JSON [{id, termino, has_children, has_materias}]

require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$parentId = trim((string)($_GET['id'] ?? ''));
if ($parentId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'missing id']);
    exit;
}

try {
    // Hijos por relación esPadreDe
    $rows = q(
        "SELECT t2.id, t2.termino
         FROM relaciones r
         JOIN terminos t2 ON t2.id = r.id2
         WHERE r.relacion = 'esPadreDe' AND r.id1 = ?
         ORDER BY t2.termino",
        's',
        [$parentId]
    );

    $out = [];
    foreach ($rows as $r) {
        $id = (string)($r['id'] ?? '');
        $term = (string)($r['termino'] ?? '');

        // ¿tiene hijos?
        $hasChildren = !empty(q(
            "SELECT 1 FROM relaciones rr WHERE rr.relacion = 'esPadreDe' AND rr.id1 = ? LIMIT 1",
            's',
            [$id]
        ));

        // ¿tiene materias? (como verificaMaterias() en Java)
        $hasMaterias = false;
        if ($term !== '') {
            $hasMaterias = !empty(q(
                "SELECT 1 FROM materias m WHERE m.materia LIKE CONCAT(?, '%') LIMIT 1",
                's',
                [$term]
            ));
        }

        $out[] = [
            'id' => $id,
            'termino' => $term,
            'has_children' => $hasChildren,
            'has_materias' => $hasMaterias,
        ];
    }

    echo json_encode(['items' => $out], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
