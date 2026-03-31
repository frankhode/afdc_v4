<?php
declare(strict_types=1);

function cmp_h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function cmp_slug(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $map = ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n'];
    $s = strtr($s, $map);
    $s = preg_replace('/[^a-z0-9]+/u', '-', $s) ?? '';
    return trim($s, '-');
}

function cmp_bootstrap_paths(): array {
    return [
        __DIR__ . '/../../inc/bootstrap.php',
        __DIR__ . '/../inc/bootstrap.php',
        __DIR__ . '/../../../inc/bootstrap.php',
    ];
}

function cmp_require_bootstrap_if_available(): void {
    foreach (cmp_bootstrap_paths() as $path) {
        if (is_file($path)) {
            require_once $path;
            return;
        }
    }
}

function cmp_db(): mysqli {
    static $db = null;
    if ($db instanceof mysqli) {
        return $db;
    }

    if (isset($GLOBALS['mysqli']) && $GLOBALS['mysqli'] instanceof mysqli) {
        $db = $GLOBALS['mysqli'];
        return $db;
    }
    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
        $db = $GLOBALS['conn'];
        return $db;
    }

    if (function_exists('db')) {
        $candidate = db();
        if ($candidate instanceof mysqli) {
            $db = $candidate;
            return $db;
        }
    }

    throw new RuntimeException('No se encontró una conexión MySQLi disponible.');
}

function cmp_render_header(string $title, string $mainClass = 'container'): void {
    if (function_exists('render_header')) {
        render_header($title);
        return;
    }
    $headerPath = __DIR__ . '/header.php';
    if (is_file($headerPath)) {
        $pageTitle = $title;
        include $headerPath;
        return;
    }
    echo "<!doctype html><html lang=\"es\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><title>" . cmp_h($title) . "</title>";
    echo '<link rel="stylesheet" href="../assets/css/campeonatos.css">';
    echo "</head><body><main class=\"" . cmp_h($mainClass) . "\">";
    echo '<header class="cmp-topbar"><h1>' . cmp_h($title) . '</h1></header>';
}

function cmp_render_footer(): void {
    if (function_exists('render_footer')) {
        render_footer();
        return;
    }
    $footerPath = __DIR__ . '/footer.php';
    if (is_file($footerPath)) {
        include $footerPath;
        return;
    }
    echo '</main></body></html>';
}
