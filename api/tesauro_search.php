<?php
// api/tesauro_search.php
// Busca términos en TODO el tesauro y devuelve el camino jerárquico.
// Response: JSON {items:[{id, termino, has_materias, path_ids:[...], path_terms:[...], path_text:"A > B > C"}]}

require_once __DIR__ . '/../inc/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$qry = trim((string)($_GET['q'] ?? ''));
if ($qry === '' || mb_strlen($qry, 'UTF-8') < 2) {
    echo json_encode(['items' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

// Límite razonable para no matar la base
$limit = (int)($_GET['limit'] ?? 30);
if ($limit < 1) $limit = 30;
if ($limit > 100) $limit = 100;

$like = '%' . $qry . '%';

try {
    $db = db();

    // 1) matches por texto
    $stmt = $db->prepare(
        "SELECT id, termino
         FROM terminos
         WHERE termino LIKE ?
         ORDER BY termino
         LIMIT ?"
    );
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }

    $stmt->bind_param('si', $like, $limit);
    $stmt->execute();
    $res = $stmt->get_result();

    $items = [];

    // prepared auxiliares (reutilizables)
    $stmtParent = $db->prepare(
        "SELECT t1.id, t1.termino
         FROM relaciones r
         JOIN terminos t1 ON t1.id = r.id1
         WHERE r.relacion = 'esPadreDe' AND r.id2 = ?
         LIMIT 1"
    );
    if (!$stmtParent) {
        throw new RuntimeException($db->error);
    }

    $stmtHasMaterias = $db->prepare(
        "SELECT 1 FROM materias m WHERE m.materia LIKE CONCAT(?, '%') LIMIT 1"
    );
    if (!$stmtHasMaterias) {
        throw new RuntimeException($db->error);
    }

    while ($row = $res->fetch_assoc()) {
        $id = (string)($row['id'] ?? '');
        $term = (string)($row['termino'] ?? '');
        if ($id === '' || $term === '') continue;

        // 2) has_materias
        $stmtHasMaterias->bind_param('s', $term);
        $stmtHasMaterias->execute();
        $r2 = $stmtHasMaterias->get_result();
        $hasMaterias = ($r2 && $r2->num_rows > 0);

        // 3) camino hacia arriba (padre por esPadreDe)
        //    Nota: asumimos árbol (1 solo padre). Si hubiese múltiples, toma el primero.
        $pathIds = [$id];
        $pathTerms = [$term];

        $curId = $id;
        $guard = 0;
        while (true) {
            $guard++;
            if ($guard > 80) break; // evita loops raros

            $stmtParent->bind_param('s', $curId);
            $stmtParent->execute();
            $rp = $stmtParent->get_result();
            if (!$rp || $rp->num_rows === 0) break;

            $p = $rp->fetch_assoc();
            $pid = (string)($p['id'] ?? '');
            $pt = (string)($p['termino'] ?? '');
            if ($pid === '' || $pt === '') break;

            array_unshift($pathIds, $pid);
            array_unshift($pathTerms, $pt);

            $curId = $pid;
        }

        $items[] = [
            'id' => $id,
            'termino' => $term,
            'has_materias' => $hasMaterias,
            'path_ids' => $pathIds,
            'path_terms' => $pathTerms,
            'path_text' => implode(' > ', $pathTerms),
        ];
    }

    $stmt->close();
    $stmtParent->close();
    $stmtHasMaterias->close();
    $db->close();

    echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
