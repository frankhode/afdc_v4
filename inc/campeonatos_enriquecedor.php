<?php
declare(strict_types=1);

/**
 * Enriquecimiento previo al guardado de importaciones de campeonatos.
 *
 * Adaptado al parser actual:
 * - los partidos NO son nodos independientes
 * - viven dentro de cada nodo fecha/ronda en `matches[]`
 * - cada match usa claves como:
 *   home, away, home_goals, away_goals, source_line
 */

/* =========================================================
 * Helpers de texto
 * ========================================================= */

function cmp_text_strip_accents(string $s): string {
    if ($s === '') return '';

    if (class_exists('Normalizer')) {
        $norm = \Normalizer::normalize($s, \Normalizer::FORM_D);
        if (is_string($norm)) {
            $s = preg_replace('/\p{Mn}+/u', '', $norm) ?? $s;
        }
    }

    $map = [
        'á'=>'a','à'=>'a','ä'=>'a','â'=>'a',
        'é'=>'e','è'=>'e','ë'=>'e','ê'=>'e',
        'í'=>'i','ì'=>'i','ï'=>'i','î'=>'i',
        'ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o',
        'ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u',
        'ñ'=>'n',
        'Á'=>'A','À'=>'A','Ä'=>'A','Â'=>'A',
        'É'=>'E','È'=>'E','Ë'=>'E','Ê'=>'E',
        'Í'=>'I','Ì'=>'I','Ï'=>'I','Î'=>'I',
        'Ó'=>'O','Ò'=>'O','Ö'=>'O','Ô'=>'O',
        'Ú'=>'U','Ù'=>'U','Ü'=>'U','Û'=>'U',
        'Ñ'=>'N',
    ];

    return strtr($s, $map);
}

function cmp_normalize_spaces(string $s): string {
    return preg_replace('/\s+/u', ' ', trim($s)) ?? trim($s);
}

function cmp_normalize_team_name(string $raw): string {
    $s = trim($raw);
    if ($s === '') return '';

    $s = cmp_text_strip_accents($s);
    $s = mb_strtolower($s, 'UTF-8');

    $s = str_replace(['.', ',', ';', ':', '"', "'", '´', '`'], ' ', $s);
    $s = preg_replace('/[\(\)\[\]\{\}]/u', ' ', $s) ?? $s;
    $s = preg_replace('/[\/\\\\\-]+/u', ' ', $s) ?? $s;
    $s = cmp_normalize_spaces($s);

    $rules = [
        '/\bfc\b/u' => '',
        '/\bclub atletico\b/u' => '',
        '/\batletico\b/u' => '',
        '/\bde futbol\b/u' => '',
        '/\bde la plata\b/u' => ' la plata',
        '/\bl p\b/u' => ' la plata',
        '/\blp\b/u' => ' la plata',
        '/\bgimnasia y esgrima\b/u' => 'gimnasia',
        '/\bargentinos jrs\b/u' => 'argentinos juniors',
        '/\barg jrs\b/u' => 'argentinos juniors',
        '/\bnewells\b/u' => 'newells',
        '/\bnewell s\b/u' => 'newells',
        '/\bvelez\b/u' => 'velez',
        '/\briver p\b/u' => 'river plate',
        '/\briver plate\b/u' => 'river plate',
        '/\bcentral cba\b/u' => 'central cordoba',
    ];

    foreach ($rules as $pattern => $replacement) {
        $s = preg_replace($pattern, $replacement, $s) ?? $s;
    }

    return cmp_normalize_spaces($s);
}

function cmp_normalize_person_name(string $raw): string {
    $s = trim($raw);
    if ($s === '') return '';

    $s = cmp_text_strip_accents($s);
    $s = mb_strtolower($s, 'UTF-8');
    $s = str_replace(['.', ',', ';', ':', '"', "'", '´', '`', '(', ')', '[', ']'], ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;

    return trim($s);
}

/* =========================================================
 * Catálogo de equipos
 * ========================================================= */

function cmp_load_team_catalog(mysqli $db): array {
    $catalog = [];

    $sql = "
        SELECT equipo
        FROM (
            SELECT TRIM(equipo1) AS equipo FROM partidos
            UNION
            SELECT TRIM(equipo2) AS equipo FROM partidos
        ) q
        WHERE equipo IS NOT NULL AND equipo <> ''
        ORDER BY equipo
    ";

    $st = $db->prepare($sql);
    if ($st) {
        $st->execute();
        $res = $st->get_result();
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $canon = trim((string)$row['equipo']);
                if ($canon === '') {
                    continue;
                }

                $norm = cmp_normalize_team_name($canon);
                if ($norm !== '' && !isset($catalog[$norm])) {
                    $catalog[$norm] = [
                        'team_name' => $canon,
                        'match_status' => 'exacto',
                        'source' => 'partidos',
                    ];
                }
            }
        }
        $st->close();
    }

    $exists = false;
    $resTbl = $db->query("SHOW TABLES LIKE 'equipos_alias'");
    if ($resTbl && $resTbl->num_rows > 0) {
        $exists = true;
    }

    if ($exists) {
        $sqlAlias = "
            SELECT ea.alias, ea.alias_normalizado, p.equipo
            FROM equipos_alias ea
            INNER JOIN (
                SELECT TRIM(equipo1) AS equipo FROM partidos
                UNION
                SELECT TRIM(equipo2) AS equipo FROM partidos
            ) p
                ON p.equipo = ea.equipo_nombre
        ";

        $stAlias = $db->prepare($sqlAlias);
        if ($stAlias) {
            $stAlias->execute();
            $resAlias = $stAlias->get_result();
            if ($resAlias) {
                while ($row = $resAlias->fetch_assoc()) {
                    $canon = trim((string)($row['equipo'] ?? ''));
                    $alias = trim((string)($row['alias_normalizado'] ?? ''));

                    if ($alias === '') {
                        $alias = cmp_normalize_team_name((string)($row['alias'] ?? ''));
                    }

                    if ($canon !== '' && $alias !== '' && !isset($catalog[$alias])) {
                        $catalog[$alias] = [
                            'team_name' => $canon,
                            'match_status' => 'alias',
                            'source' => 'equipos_alias',
                        ];
                    }
                }
            }
            $stAlias->close();
        }
    }

    return $catalog;
}

function cmp_resolve_team_name(string $raw, array $catalog): array {
    $raw = trim($raw);
    $norm = cmp_normalize_team_name($raw);

    if ($raw === '') {
        return [
            'raw' => '',
            'normalized' => '',
            'canonical' => '',
            'match_status' => 'vacio',
        ];
    }

    if ($norm !== '' && isset($catalog[$norm])) {
        return [
            'raw' => $raw,
            'normalized' => $norm,
            'canonical' => (string)$catalog[$norm]['team_name'],
            'match_status' => (string)$catalog[$norm]['match_status'],
        ];
    }

    $hits = [];
    foreach ($catalog as $k => $info) {
        if ($k !== '' && (str_contains($k, $norm) || str_contains($norm, $k))) {
            $hits[] = $info;
        }
    }

    if (count($hits) === 1) {
        return [
            'raw' => $raw,
            'normalized' => $norm,
            'canonical' => (string)$hits[0]['team_name'],
            'match_status' => 'aproximado',
        ];
    }

    if (count($hits) > 1) {
        return [
            'raw' => $raw,
            'normalized' => $norm,
            'canonical' => '',
            'match_status' => 'ambiguo',
        ];
    }

    return [
        'raw' => $raw,
        'normalized' => $norm,
        'canonical' => '',
        'match_status' => 'sin_match',
    ];
}

/* =========================================================
 * Goleadores
 * ========================================================= */

function cmp_extract_goal_scorers_from_text(string $text, string $homeCanonical = '', string $awayCanonical = ''): array {
    $text = trim($text);
    if ($text === '') {
        return [];
    }

    if (preg_match('/goles?\s*[:\-]\s*(.+)$/iu', $text, $m)) {
        $text = trim($m[1]);
    }

    $segments = preg_split('/\s*[;|]\s*/u', $text) ?: [$text];
    $events = [];
    $order = 1;

    foreach ($segments as $segment) {
        $segment = trim($segment);
        if ($segment === '') {
            continue;
        }

        $side = 'desconocido';
        $team = '';

        if ($homeCanonical !== '' && preg_match('/^' . preg_quote($homeCanonical, '/') . '\s*:\s*/iu', $segment)) {
            $side = 'local';
            $team = $homeCanonical;
            $segment = preg_replace('/^' . preg_quote($homeCanonical, '/') . '\s*:\s*/iu', '', $segment) ?? $segment;
        } elseif ($awayCanonical !== '' && preg_match('/^' . preg_quote($awayCanonical, '/') . '\s*:\s*/iu', $segment)) {
            $side = 'visitante';
            $team = $awayCanonical;
            $segment = preg_replace('/^' . preg_quote($awayCanonical, '/') . '\s*:\s*/iu', '', $segment) ?? $segment;
        }

        $parts = preg_split('/\s*,\s*/u', $segment) ?: [$segment];

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $player = $part;
            $minute = null;
            $goalType = 'normal';

            if (preg_match('/^(.*?)\s*\((\d+)\)\s*$/u', $part, $m)) {
                $player = trim($m[1]);
                $qty = (int)$m[2];

                for ($i = 0; $i < max(1, $qty); $i++) {
                    $events[] = [
                        'order' => $order++,
                        'team_side' => $side,
                        'team_name' => $team,
                        'player_raw' => $player,
                        'player_normalized' => cmp_normalize_person_name($player),
                        'minute' => null,
                        'goal_type' => $goalType,
                        'raw_fragment' => $part,
                    ];
                }
                continue;
            }

            if (preg_match('/^(.*?)\s+(\d{1,3})\'?$/u', $part, $m)) {
                $player = trim($m[1]);
                $minute = (int)$m[2];
            } elseif (preg_match('/^(.*?)\s+\(?(\d{1,3})\'?\)?$/u', $part, $m)) {
                $player = trim($m[1]);
                $minute = (int)$m[2];
            }

            if (preg_match('/\bpen(al)?\b/iu', $part)) {
                $goalType = 'penal';
            } elseif (preg_match('/\ben contra\b/iu', $part)) {
                $goalType = 'en_contra';
            }

            $player = preg_replace('/\bpen(al)?\b/iu', '', $player) ?? $player;
            $player = preg_replace('/\ben contra\b/iu', '', $player) ?? $player;
            $player = trim($player);

            if ($player === '') {
                continue;
            }

            $events[] = [
                'order' => $order++,
                'team_side' => $side,
                'team_name' => $team,
                'player_raw' => $player,
                'player_normalized' => cmp_normalize_person_name($player),
                'minute' => $minute,
                'goal_type' => $goalType,
                'raw_fragment' => $part,
            ];
        }
    }

    return $events;
}

/* =========================================================
 * Enriquecimiento sobre matches[]
 * ========================================================= */

function cmp_enrich_import_with_teams(array $parsed, mysqli $db): array {
    $catalog = cmp_load_team_catalog($db);

    if (!isset($parsed['tree']) || !is_array($parsed['tree'])) {
        return $parsed;
    }

    $parsed['tree'] = cmp_walk_tree_enrich_matches_with_teams($parsed['tree'], $catalog);
    return $parsed;
}

function cmp_walk_tree_enrich_matches_with_teams(array $node, array $catalog): array {
    if (!empty($node['matches']) && is_array($node['matches'])) {
        foreach ($node['matches'] as $idx => $match) {
            if (!is_array($match)) {
                continue;
            }

            $homeRaw = trim((string)($match['home'] ?? ''));
            $awayRaw = trim((string)($match['away'] ?? ''));

            $home = cmp_resolve_team_name($homeRaw, $catalog);
            $away = cmp_resolve_team_name($awayRaw, $catalog);

            $match['home_team_raw'] = $home['raw'];
            $match['home_team_normalized'] = $home['normalized'];
            $match['home_team_canonical'] = $home['canonical'];
            $match['home_team_match_status'] = $home['match_status'];

            $match['away_team_raw'] = $away['raw'];
            $match['away_team_normalized'] = $away['normalized'];
            $match['away_team_canonical'] = $away['canonical'];
            $match['away_team_match_status'] = $away['match_status'];

            $warnings = [];
            if ($home['match_status'] === 'sin_match' || $away['match_status'] === 'sin_match') {
                $warnings[] = 'equipo_sin_match';
            }
            if ($home['match_status'] === 'ambiguo' || $away['match_status'] === 'ambiguo') {
                $warnings[] = 'equipo_ambiguo';
            }
            if ($warnings) {
                $match['import_warnings'] = array_values(array_unique(array_merge(
                    (array)($match['import_warnings'] ?? []),
                    $warnings
                )));
            }

            $node['matches'][$idx] = $match;
        }
    }

    if (!empty($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $idx => $child) {
            if (is_array($child)) {
                $node['children'][$idx] = cmp_walk_tree_enrich_matches_with_teams($child, $catalog);
            }
        }
    }

    return $node;
}

function cmp_enrich_import_with_goal_scorers(array $parsed): array {
    if (!isset($parsed['tree']) || !is_array($parsed['tree'])) {
        return $parsed;
    }

    $parsed['tree'] = cmp_walk_tree_enrich_matches_with_goals($parsed['tree']);
    return $parsed;
}

function cmp_walk_tree_enrich_matches_with_goals(array $node): array {
    if (!empty($node['matches']) && is_array($node['matches'])) {
        foreach ($node['matches'] as $idx => $match) {
            if (!is_array($match)) {
                continue;
            }

            $goalText = trim((string)($match['goal_text_raw'] ?? ''));

            // Por ahora, si no hay campo específico, usamos la línea fuente.
            if ($goalText === '') {
                $goalText = trim((string)($match['source_line'] ?? ''));
            }

            $homeCanonical = trim((string)($match['home_team_canonical'] ?? ''));
            $awayCanonical = trim((string)($match['away_team_canonical'] ?? ''));

            $events = cmp_extract_goal_scorers_from_text($goalText, $homeCanonical, $awayCanonical);
            if (!empty($events)) {
                $match['goal_text_raw'] = $goalText;
                $match['goal_events'] = $events;
                $match['goal_events_count'] = count($events);
            }

            $node['matches'][$idx] = $match;
        }
    }

    if (!empty($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $idx => $child) {
            if (is_array($child)) {
                $node['children'][$idx] = cmp_walk_tree_enrich_matches_with_goals($child);
            }
        }
    }

    return $node;
}