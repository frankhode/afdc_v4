<?php
/**
 * AFDC v1 - Bootstrap (MySQLi)
 * Incluye config, helpers comunes y settings PHP.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config.php';

// Zona horaria (ajustable)
date_default_timezone_set('America/Argentina/Buenos_Aires');

// Helpers
function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function g(string $key, $default = '') {
    return $_GET[$key] ?? $default;
}

function p(string $key, $default = '') {
    return $_POST[$key] ?? $default;
}

function is_nonempty_string($v): bool {
    return is_string($v) && trim($v) !== '';
}

/**
 * Arma URL manteniendo querystring actual + overrides.
 * - Pasá null para remover una key.
 */
function url_with(array $params, ?string $base = null): string {
    $base = $base ?? ($_SERVER['SCRIPT_NAME'] ?? 'index.php');
    $current = $_GET ?? [];
    foreach ($params as $k => $v) {
        if ($v === null) unset($current[$k]);
        else $current[$k] = $v;
    }
    $qs = http_build_query($current);
    return $qs ? ($base . '?' . $qs) : $base;
}

/**
 * Helper para prepared statements (MySQLi) con tipos.
 * Ej: $rows = q("SELECT * FROM t WHERE a=? AND b>?", "si", [$a, $b]);
 */
function q(string $sql, string $types = '', array $params = []): array {
    $db = db();
    $stmt = $db->prepare($sql);
    if ($types !== '') {
        // bind_param necesita variables por referencia
        $refs = [];
        foreach ($params as $i => $v) $refs[$i] = &$params[$i];
        $stmt->bind_param($types, ...$refs);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res) return [];
    return $res->fetch_all(MYSQLI_ASSOC);
}
