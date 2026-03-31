<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (!function_exists('fot_h')) {
    function fot_h(?string $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('fot_slugify')) {
    function fot_slugify(string $s): string
    {
        $s = trim($s);
        if ($s === '') return '';

        $map = [
            'á'=>'a','à'=>'a','ä'=>'a','â'=>'a','Á'=>'A','À'=>'A','Ä'=>'A','Â'=>'A',
            'é'=>'e','è'=>'e','ë'=>'e','ê'=>'e','É'=>'E','È'=>'E','Ë'=>'E','Ê'=>'E',
            'í'=>'i','ì'=>'i','ï'=>'i','î'=>'i','Í'=>'I','Ì'=>'I','Ï'=>'I','Î'=>'I',
            'ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o','Ó'=>'O','Ò'=>'O','Ö'=>'O','Ô'=>'O',
            'ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u','Ú'=>'U','Ù'=>'U','Ü'=>'U','Û'=>'U',
            'ñ'=>'n','Ñ'=>'N','ç'=>'c','Ç'=>'C'
        ];
        $s = strtr($s, $map);
        $s = mb_strtolower($s, 'UTF-8');
        $s = preg_replace('/[^a-z0-9]+/u', '-', $s) ?? '';
        $s = trim($s, '-');
        return $s !== '' ? $s : 'fotografo';
    }
}

if (!function_exists('fot_base_url')) {
    function fot_base_url(string $path = ''): string
    {
        $base = defined('BASE_URL') ? rtrim((string)BASE_URL, '/') : '';
        if ($path === '') return $base;
        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('fot_page_url')) {
    function fot_page_url(string $path, array $params = []): string
    {
        $url = fot_base_url($path);
        if (!$params) return $url;
        return $url . '?' . http_build_query($params);
    }
}

if (!function_exists('fot_normalize_search')) {
    function fot_normalize_search(string $s): string
    {
        $s = trim($s);
        $s = mb_strtolower($s, 'UTF-8');
        $map = [
            'á'=>'a','à'=>'a','ä'=>'a','â'=>'a',
            'é'=>'e','è'=>'e','ë'=>'e','ê'=>'e',
            'í'=>'i','ì'=>'i','ï'=>'i','î'=>'i',
            'ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o',
            'ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u',
            'ñ'=>'n','ç'=>'c'
        ];
        return strtr($s, $map);
    }
}

if (!function_exists('fot_build_image_url')) {
    function fot_build_image_url(array $fotografo): string
    {
        $tipo = (string)($fotografo['imagen_tipo'] ?? 'ninguna');
        $valor = trim((string)($fotografo['imagen_valor'] ?? ''));

        if ($tipo === 'url' && $valor !== '') {
            return $valor;
        }

        if ($tipo === 'barcode' && $valor !== '') {
            return fot_page_url('ver_digital.php', ['barcode' => $valor, 'i' => 0]);
        }

        if ($tipo === 'recorte' && $valor !== '') {
            return fot_page_url('misrecortes.php', ['recorte' => $valor]);
        }

        return '';
    }
}

if (!function_exists('fot_fetch_all_visible')) {
    function fot_fetch_all_visible(string $q = ''): array
    {
        $rows = q("
            SELECT
                f.id,
                f.apellido,
                f.nombre,
                f.nombre_mostrar,
                f.slug,
                f.fecha_nacimiento,
                f.fecha_fallecimiento,
                f.bio,
                f.imagen_tipo,
                f.imagen_valor,
                f.visible,
                COUNT(DISTINCT fs.barcode) AS sobres_count
            FROM fotografos f
            LEFT JOIN fotografos_sobres fs ON fs.fotografo_id = f.id
            WHERE f.visible = 1
            GROUP BY
                f.id, f.apellido, f.nombre, f.nombre_mostrar, f.slug,
                f.fecha_nacimiento, f.fecha_fallecimiento, f.bio,
                f.imagen_tipo, f.imagen_valor, f.visible
            ORDER BY
                CASE WHEN f.apellido <> '' THEN f.apellido ELSE f.nombre_mostrar END ASC,
                f.nombre ASC,
                f.nombre_mostrar ASC
        ");

        if ($q === '') {
            return $rows;
        }

        $needle = fot_normalize_search($q);
        return array_values(array_filter($rows, static function (array $r) use ($needle): bool {
            $haystack = fot_normalize_search(
                (string)($r['nombre_mostrar'] ?? '') . ' ' .
                (string)($r['apellido'] ?? '') . ' ' .
                (string)($r['nombre'] ?? '')
            );
            return str_contains($haystack, $needle);
        }));
    }
}

if (!function_exists('fot_fetch_one')) {
    function fot_fetch_one(?int $id = null, ?string $slug = null): ?array
    {
        if ($id !== null) {
            $rows = q("
                SELECT
                    f.*,
                    COUNT(DISTINCT fs.barcode) AS sobres_count
                FROM fotografos f
                LEFT JOIN fotografos_sobres fs ON fs.fotografo_id = f.id
                WHERE f.id = ?
                GROUP BY f.id
                LIMIT 1
            ", 'i', [$id]);

            return $rows[0] ?? null;
        }

        if ($slug !== null && $slug !== '') {
            $rows = q("
                SELECT
                    f.*,
                    COUNT(DISTINCT fs.barcode) AS sobres_count
                FROM fotografos f
                LEFT JOIN fotografos_sobres fs ON fs.fotografo_id = f.id
                WHERE f.slug = ?
                GROUP BY f.id
                LIMIT 1
            ", 's', [$slug]);

            return $rows[0] ?? null;
        }

        return null;
    }
}

if (!function_exists('fot_fetch_variantes')) {
    function fot_fetch_variantes(int $fotografoId): array
    {
        return q("
            SELECT
                fs.autor_raw,
                COUNT(DISTINCT fs.barcode) AS sobres_count
            FROM fotografos_sobres fs
            WHERE fs.fotografo_id = ?
              AND fs.autor_raw <> ''
            GROUP BY fs.autor_raw
            ORDER BY sobres_count DESC, fs.autor_raw ASC
        ", 'i', [$fotografoId]);
    }
}

if (!function_exists('fot_fetch_sobres_by_fotografo')) {
    function fot_fetch_sobres_by_fotografo(int $fotografoId, string $q = '', int $limit = 500): array
    {
        $sql = "
            SELECT
                fs.barcode,
                MAX(i.fechaIso) AS fecha,
                MAX(i.titulo) AS titulo,
                MAX(i.autor) AS autor_inventario,
                GROUP_CONCAT(DISTINCT fs.autor_raw ORDER BY fs.autor_raw SEPARATOR ' | ') AS raws_detectados,
                COUNT(DISTINCT d.inv) AS digitales_count
            FROM fotografos_sobres fs
            LEFT JOIN inventario i ON i.barcode = fs.barcode
            LEFT JOIN digitales d
                ON d.inv = fs.barcode
               AND d.carpeta IN ('Bajas','Altas')
            WHERE fs.fotografo_id = ?
        ";

        $types = 'i';
        $params = [$fotografoId];

        if ($q !== '') {
            $sql .= " AND (
                fs.barcode LIKE ?
                OR i.titulo LIKE ?
                OR i.autor LIKE ?
            )";
            $like = '%' . $q . '%';
            $types .= 'sss';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql .= "
            GROUP BY fs.barcode
            ORDER BY
                CASE WHEN MAX(i.fechaIso) IS NULL OR MAX(i.fechaIso) = '' THEN 1 ELSE 0 END ASC,
                MAX(i.fechaIso) DESC,
                fs.barcode ASC
            LIMIT ?
        ";

        $types .= 'i';
        $params[] = $limit;

        return q($sql, $types, $params);
    }
}

if (!function_exists('fot_format_fechas')) {
    function fot_format_fechas(array $fotografo): string
    {
        $n = trim((string)($fotografo['fecha_nacimiento'] ?? ''));
        $f = trim((string)($fotografo['fecha_fallecimiento'] ?? ''));

        if ($n !== '' && $f !== '') return $n . ' – ' . $f;
        if ($n !== '') return 'n. ' . $n;
        if ($f !== '') return 'f. ' . $f;
        return '';
    }
}

if (!function_exists('fot_excerpt')) {
    function fot_excerpt(?string $text, int $max = 220): string
    {
        $text = trim((string)$text);
        if ($text === '') return '';
        if (mb_strlen($text, 'UTF-8') <= $max) return $text;
        return rtrim(mb_substr($text, 0, $max, 'UTF-8')) . '…';
    }
}