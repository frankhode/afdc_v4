<?php
declare(strict_types=1);

require_once __DIR__ . '/campeonatos_helpers.php';

function cmp_presets(): array {
    return [
        'liga_por_fechas' => [
            'label' => 'Liga por fechas',
            'shape' => 'torneo > fase > fecha > partido',
        ],
        'liga_doble_por_fechas' => [
            'label' => 'Liga doble por fechas',
            'shape' => 'torneo > fase > fecha > partido',
        ],
        'grupos_interzonal_y_playoff' => [
            'label' => 'Grupos con interzonal y playoff',
            'shape' => 'torneo > fase(grupos) > grupo > fecha > partido + fase(final) > ronda > fecha > partido',
        ],
    ];
}

function cmp_parse_import(string $sourceType, string $sourceValue): array {
    if ($sourceType !== 'text') {
        throw new InvalidArgumentException('En este hito la importación es solo por texto pegado.');
    }

    $text = cmp_normalize_plain_text($sourceValue);
    if ($text === '') {
        throw new InvalidArgumentException('Pegá el texto del torneo antes de importar.');
    }

    $season = cmp_guess_season($text);
    $title = cmp_guess_title($text);

    // En este hito asumimos un torneo por import.
    $tournamentLabel = cmp_guess_tournament_label($text, $season);
    $tree = [
        'type' => 'temporada',
        'subtype' => null,
        'label' => $season ? (string)$season : 'Temporada importada',
        'meta' => ['season' => $season],
        'children' => [
            cmp_parse_tournament($tournamentLabel, $text, 1),
        ],
    ];

    return [
        'title' => $title,
        'season' => $season,
        'source_url' => null,
        'raw_text' => $text,
        'tree' => $tree,
    ];
}

function cmp_normalize_plain_text(string $text): string {
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace("/\xC2\xA0/u", ' ', $text) ?? $text;
    $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
    $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;
    return trim($text);
}

function cmp_guess_season(string $text): ?int {
    if (preg_match('/\b(19\d{2}|20\d{2})\b/u', $text, $m)) {
        return (int)$m[1];
    }
    return null;
}

function cmp_guess_title(string $text): string {
    $lines = preg_split('/\n+/u', $text) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') {
            return mb_substr($line, 0, 180);
        }
    }
    return 'Importación de campeonato';
}

function cmp_guess_tournament_label(string $text, ?int $season): string {
    $lines = preg_split('/\n+/u', $text) ?: [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        if (preg_match('/\b(metropolitano|nacional)\b/iu', $line)) {
            return $line;
        }
    }

    return $season ? ('Torneo ' . $season) : 'Torneo importado';
}

function cmp_detect_preset(string $tournamentLabel, string $text): string {
    $hayGrupos = (bool)preg_match('/\b(zona|zone|grupo|group)\s+[a-z0-9]+\b/iu', $text);
    $hayFinales = (bool)preg_match('/\b(semifinal(?:es)?|semifinals?|final(?:es)?|quarterfinals?|cuartos(?: de final)?|ida|vuelta)\b/iu', $text);
    $hayInterzonal = (bool)preg_match('/\binterzonal\b/iu', $text);

    if ($hayGrupos || $hayFinales || $hayInterzonal || preg_match('/\bnacional\b/iu', $tournamentLabel)) {
        return 'grupos_interzonal_y_playoff';
    }

    if (preg_match('/\bmetropolitano\b/iu', $tournamentLabel)) {
        return 'liga_doble_por_fechas';
    }

    return 'liga_por_fechas';
}

function cmp_parse_tournament(string $label, string $text, int $order): array {
    $preset = cmp_detect_preset($label, $text);

    if ($preset === 'grupos_interzonal_y_playoff') {
        return cmp_parse_tournament_groups_playoff($label, $text, $order, $preset);
    }

    return cmp_parse_tournament_league($label, $text, $order, $preset);
}

function cmp_parse_tournament_league(string $label, string $text, int $order, string $preset): array {
    $tournament = [
        'type' => 'torneo',
        'subtype' => null,
        'label' => $label,
        'order' => $order,
        'meta' => ['preset_id' => $preset],
        'children' => [],
    ];

    $phase = [
        'type' => 'fase',
        'subtype' => 'regular',
        'label' => 'Fase regular',
        'children' => [],
    ];

    $dateNodes = cmp_extract_date_nodes($text, 'regular');

    if ($dateNodes === []) {
        $dateNodes = cmp_build_date_nodes_from_match_blocks($text, 'Bloque', 'regular');
    }

    if ($dateNodes === []) {
        $phase['children'][] = [
            'type' => 'nota',
            'subtype' => 'sin_partidos',
            'label' => 'No se detectaron fechas ni bloques de partidos',
            'text_original' => mb_substr($text, 0, 3000),
            'children' => [],
        ];
    } else {
        $phase['children'] = $dateNodes;
    }

    $tournament['children'][] = $phase;

    return $tournament;
}

function cmp_parse_tournament_groups_playoff(string $label, string $text, int $order, string $preset): array {
    $tournament = [
        'type' => 'torneo',
        'subtype' => null,
        'label' => $label,
        'order' => $order,
        'meta' => ['preset_id' => $preset],
        'children' => [],
    ];

    $groupPhase = [
        'type' => 'fase',
        'subtype' => 'grupos',
        'label' => 'Fase de grupos',
        'children' => [],
    ];

    $finalPhase = [
        'type' => 'fase',
        'subtype' => 'final',
        'label' => 'Fase final',
        'children' => [],
    ];

    $groups = cmp_split_groups($text);
    foreach ($groups as $groupOrder => $group) {
        $dateNodes = cmp_extract_date_nodes($group['text'], 'regular');
        if ($dateNodes === []) {
            $dateNodes = cmp_build_date_nodes_from_match_blocks($group['text'], 'Bloque', 'regular');
        }

        $groupNode = [
            'type' => 'grupo',
            'subtype' => null,
            'label' => $group['label'],
            'order' => $groupOrder + 1,
            'children' => $dateNodes,
        ];

        if ($groupNode['children'] === []) {
            $groupNode['children'][] = [
                'type' => 'nota',
                'subtype' => 'sin_partidos',
                'label' => 'Sin fechas ni partidos detectados en grupo',
                'text_original' => mb_substr($group['text'], 0, 2500),
                'children' => [],
            ];
        }

        $groupPhase['children'][] = $groupNode;
    }

    $rounds = cmp_extract_knockout_rounds($text);
    foreach ($rounds as $roundOrder => $round) {
        $dateNodes = cmp_extract_date_nodes($round['text'], 'eliminatoria');

        if ($dateNodes === []) {
            $matches = cmp_extract_match_lines($round['text']);
            if ($matches !== []) {
                $dateNodes = [[
                    'type' => 'fecha',
                    'subtype' => 'eliminatoria',
                    'label' => $round['label'],
                    'order' => 1,
                    'text_original' => $round['text'],
                    'matches' => $matches,
                    'children' => [],
                ]];
            }
        }

        if ($dateNodes === []) {
            $dateNodes = cmp_build_date_nodes_from_match_blocks($round['text'], 'Bloque', 'eliminatoria');
        }

        $roundChildren = cmp_group_knockout_series($dateNodes);

        $finalPhase['children'][] = [
            'type' => 'ronda',
            'subtype' => cmp_round_subtype($round['label']),
            'label' => $round['label'],
            'order' => $roundOrder + 1,
            'children' => $roundChildren,
        ];
    }

    if ($groupPhase['children'] !== []) {
        $tournament['children'][] = $groupPhase;
    }

    if ($finalPhase['children'] !== []) {
        $tournament['children'][] = $finalPhase;
    }

    if ($tournament['children'] === []) {
        return cmp_parse_tournament_league($label, $text, $order, $preset);
    }

    return $tournament;
}

function cmp_extract_date_nodes(string $text, string $defaultSubtype = 'regular'): array {
    $lines = preg_split('/\n/u', $text) ?: [];
    $nodes = [];

    $currentLabel = null;
    $currentSubtype = $defaultSubtype;
    $currentLines = [];

    foreach ($lines as $line) {
        $trim = trim($line);
        if ($trim === '') {
            if ($currentLabel !== null) {
                $currentLines[] = '';
            }
            continue;
        }

        if (cmp_is_date_heading($trim)) {
            if ($currentLabel !== null) {
                $node = cmp_finalize_date_node($currentLabel, $currentSubtype, $currentLines, count($nodes) + 1);
                if ($node !== null) {
                    $nodes[] = $node;
                }
            }

            $currentLabel = $trim;
            $currentSubtype = cmp_date_subtype_from_label($trim, $defaultSubtype);
            $currentLines = [];
            continue;
        }

        if ($currentLabel !== null) {
            $currentLines[] = $trim;
        }
    }

    if ($currentLabel !== null) {
        $node = cmp_finalize_date_node($currentLabel, $currentSubtype, $currentLines, count($nodes) + 1);
        if ($node !== null) {
            $nodes[] = $node;
        }
    }

    return $nodes;
}

function cmp_finalize_date_node(string $label, string $subtype, array $lines, int $order): ?array {
    $body = trim(implode("\n", $lines));
    $matches = cmp_extract_match_lines($body);

    if ($matches === []) {
        return null;
    }

    return [
        'type' => 'fecha',
        'subtype' => $subtype,
        'label' => $label,
        'order' => $order,
        'text_original' => $body,
        'matches' => $matches,
        'children' => [],
    ];
}

function cmp_is_date_heading(string $line): bool {
    return (bool)preg_match(
        '/^(' .
        'fecha\s+\d+' .
        '|jornada\s+\d+' .
        '|matchday\s+\d+' .
        '|round\s+\d+' .
        '|(?:\d{1,2}(?:st|nd|rd|th)\s+round)' .
        '|fecha\s+interzonal' .
        '|interzonal(?:\s+\d+)?' .
        '|ida' .
        '|vuelta' .
        ')$/iu',
        $line
    );
}

function cmp_date_subtype_from_label(string $label, string $defaultSubtype = 'regular'): string {
    if (preg_match('/\binterzonal\b/iu', $label)) {
        return 'interzonal';
    }
    if (preg_match('/^ida$/iu', $label)) {
        return 'ida';
    }
    if (preg_match('/^vuelta$/iu', $label)) {
        return 'vuelta';
    }
    return $defaultSubtype;
}

function cmp_split_groups(string $text): array {
    $lines = preg_split('/\n/u', $text) ?: [];
    $groups = [];

    $currentLabel = null;
    $buffer = [];

    foreach ($lines as $line) {
        $trim = trim($line);
        if ($trim === '') {
            if ($currentLabel !== null) {
                $buffer[] = '';
            }
            continue;
        }

        if (cmp_is_group_heading($trim)) {
            if ($currentLabel !== null) {
                $groups[] = [
                    'label' => $currentLabel,
                    'text' => trim(implode("\n", $buffer)),
                ];
            }
            $currentLabel = $trim;
            $buffer = [];
            continue;
        }

        if ($currentLabel !== null) {
            if (cmp_is_knockout_heading($trim)) {
                break;
            }
            $buffer[] = $trim;
        }
    }

    if ($currentLabel !== null) {
        $groups[] = [
            'label' => $currentLabel,
            'text' => trim(implode("\n", $buffer)),
        ];
    }

    return $groups;
}

function cmp_is_group_heading(string $line): bool {
    return (bool)preg_match('/^(zona|zone|grupo|group)\s+[a-z0-9]+$/iu', $line);
}

function cmp_extract_knockout_rounds(string $text): array {
    $lines = preg_split('/\n/u', $text) ?: [];
    $rounds = [];

    $currentLabel = null;
    $buffer = [];

    foreach ($lines as $line) {
        $trim = trim($line);
        if ($trim === '') {
            if ($currentLabel !== null) {
                $buffer[] = '';
            }
            continue;
        }

        if (cmp_is_knockout_heading($trim)) {
            if ($currentLabel !== null) {
                $rounds[] = [
                    'label' => $currentLabel,
                    'text' => trim(implode("\n", $buffer)),
                ];
            }
            $currentLabel = $trim;
            $buffer = [];
            continue;
        }

        if ($currentLabel !== null) {
            $buffer[] = $trim;
        }
    }

    if ($currentLabel !== null) {
        $rounds[] = [
            'label' => $currentLabel,
            'text' => trim(implode("\n", $buffer)),
        ];
    }

    return $rounds;
}

function cmp_is_knockout_heading(string $line): bool {
    return (bool)preg_match(
        '/^(semifinal(?:es)?|semifinals?|final(?:es)?|quarterfinals?|cuartos(?: de final)?)$/iu',
        $line
    );
}

function cmp_round_subtype(string $label): string {
    if (preg_match('/semi/iu', $label)) {
        return 'semifinal';
    }
    if (preg_match('/quarter|cuartos/iu', $label)) {
        return 'cuartos';
    }
    if (preg_match('/final/iu', $label)) {
        return 'final';
    }
    return 'ronda';
}

function cmp_group_knockout_series(array $dateNodes): array {
    if ($dateNodes === []) {
        return [];
    }

    $seriesMap = [];
    $seriesOrder = 1;

    foreach ($dateNodes as $dateNode) {
        $matches = $dateNode['matches'] ?? [];
        foreach ($matches as $match) {
            $home = trim((string)($match['home'] ?? ''));
            $away = trim((string)($match['away'] ?? ''));

            if ($home === '' || $away === '') {
                continue;
            }

            $teams = [$home, $away];
            natcasesort($teams);
            $teams = array_values($teams);
            $key = mb_strtolower($teams[0] . '||' . $teams[1], 'UTF-8');

            if (!isset($seriesMap[$key])) {
                $seriesMap[$key] = [
                    'type' => 'serie',
                    'subtype' => 'eliminatoria',
                    'label' => cmp_series_label($teams[0], $teams[1]),
                    'order' => $seriesOrder++,
                    'children' => [],
                ];
            }

            $seriesMap[$key]['children'][] = [
                'type' => 'fecha',
                'subtype' => 'eliminatoria',
                'label' => '',
                'order' => count($seriesMap[$key]['children']) + 1,
                'text_original' => (string)($match['source_line'] ?? ''),
                'matches' => [$match],
                'children' => [],
            ];
        }
    }

    $seriesNodes = array_values($seriesMap);

    foreach ($seriesNodes as &$seriesNode) {
        $count = count($seriesNode['children']);
        foreach ($seriesNode['children'] as $idx => &$childDate) {
            if ($count === 1) {
                $childDate['subtype'] = 'unica';
                $childDate['label'] = 'Partido único';
            } elseif ($count === 2) {
                $childDate['subtype'] = $idx === 0 ? 'ida' : 'vuelta';
                $childDate['label'] = $idx === 0 ? 'Ida' : 'Vuelta';
            } else {
                $childDate['subtype'] = 'eliminatoria';
                $childDate['label'] = 'Partido ' . ($idx + 1);
            }
        }
        unset($childDate);
    }
    unset($seriesNode);

    usort($seriesNodes, static function(array $a, array $b): int {
        return ((int)($a['order'] ?? 0)) <=> ((int)($b['order'] ?? 0));
    });

    return $seriesNodes;
}

function cmp_series_label(string $teamA, string $teamB): string {
    return $teamA . ' vs ' . $teamB;
}

function cmp_build_date_nodes_from_match_blocks(string $text, string $labelPrefix = 'Bloque', string $subtype = 'regular'): array {
    $blocks = preg_split("/\n{2,}/u", trim($text)) ?: [];
    $nodes = [];
    $order = 1;

    foreach ($blocks as $block) {
        $block = trim($block);
        if ($block === '') {
            continue;
        }

        if (cmp_looks_like_table_block($block)) {
            continue;
        }

        $matches = cmp_extract_match_lines($block);
        if ($matches === []) {
            continue;
        }

        $nodes[] = [
            'type' => 'fecha',
            'subtype' => $subtype,
            'label' => $labelPrefix . ' ' . $order,
            'order' => $order,
            'text_original' => $block,
            'matches' => $matches,
            'children' => [],
        ];
        $order++;
    }

    return $nodes;
}

function cmp_looks_like_table_block(string $block): bool {
    $lines = preg_split('/\n+/u', $block) ?: [];
    $scoreLike = 0;
    $tableLike = 0;

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '') {
            continue;
        }

        if (preg_match('/\b\d+\s*[-:]\s*\d+\b/u', $line)) {
            $scoreLike++;
        }

        if (preg_match('/^\d+(\.| )/u', $line) || preg_match('/\bpts?\b/iu', $line)) {
            $tableLike++;
        }
    }

    return $tableLike > $scoreLike && $tableLike >= 2;
}

function cmp_extract_match_lines(string $text): array {
    $lines = preg_split('/\n+/u', $text) ?: [];
    $matches = [];
    $order = 1;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        if (cmp_line_is_noise($line)) {
            continue;
        }

        $parsed = cmp_parse_match_line($line);
        if ($parsed === null) {
            continue;
        }

        $parsed['order'] = $order++;
        $matches[] = $parsed;
    }

    return $matches;
}

function cmp_line_is_noise(string $line): bool {
    if (cmp_is_date_heading($line) || cmp_is_group_heading($line) || cmp_is_knockout_heading($line)) {
        return true;
    }

    if (preg_match('/^(final table|standings|table|goals?:|goles:|pts?\.?)$/iu', $line)) {
        return true;
    }

    return false;
}

function cmp_parse_match_line(string $line): ?array {
    $line = preg_replace('/^[\-\*\•]+\s*/u', '', $line) ?? $line;
    $line = preg_replace('/\s{2,}/u', ' ', $line) ?? $line;

    $patterns = [
        '/^(.+?)\s+(\d+)\s*[-–]\s*(\d+)\s+(.+)$/u',
        '/^(.+?)\s+(\d+)\s*[:]\s*(\d+)\s+(.+)$/u',
        '/^(.+?)\|\s*(\d+)\s*[-–:]\s*(\d+)\s*\|\s*(.+)$/u',
    ];

    foreach ($patterns as $pattern) {
        if (!preg_match($pattern, $line, $m)) {
            continue;
        }

        $home = trim($m[1]);
        $away = trim($m[4]);

        if (!cmp_looks_like_team($home) || !cmp_looks_like_team($away)) {
            continue;
        }

        return [
            'home' => $home,
            'away' => $away,
            'home_goals' => (int)$m[2],
            'away_goals' => (int)$m[3],
            'source_line' => $line,
        ];
    }

    return null;
}

function cmp_looks_like_team(string $value): bool {
    $value = trim($value);

    if ($value === '' || mb_strlen($value, 'UTF-8') < 2) {
        return false;
    }

    if (!preg_match('/[[:alpha:]]/u', $value)) {
        return false;
    }

    if (preg_match('/^\d+(\.| )/u', $value)) {
        return false;
    }

    if (preg_match('/^(fecha|round|jornada|zona|group|grupo|semifinal|final|ida|vuelta)\b/iu', $value)) {
        return false;
    }

    return true;
}

function cmp_build_text_audit(string $rawText, array $tree): array {
    $lines = preg_split('/\n/u', str_replace(["\r\n", "\r"], "\n", $rawText)) ?: [];

    $usedMap = [];
    cmp_collect_used_lines_from_tree($tree, $usedMap);

    $items = [];
    $used = 0;
    $unused = 0;
    $suspicious = 0;
    $empty = 0;

    foreach ($lines as $idx => $line) {
        $lineNo = $idx + 1;
        $trim = trim($line);

        if ($trim === '') {
            $status = 'empty';
            $type = 'vacía';
            $empty++;
        } elseif (isset($usedMap[$trim])) {
            $status = 'used';
            $type = $usedMap[$trim];
            $used++;
        } else {
            $guess = cmp_guess_line_status($trim);
            $status = $guess['status'];
            $type = $guess['type'];

            if ($status === 'suspicious') {
                $suspicious++;
            } else {
                $unused++;
            }
        }

        $items[] = [
            'line_no' => $lineNo,
            'text' => $line,
            'status' => $status,
            'type' => $type,
        ];
    }

    $total = count($lines);
    $coverageBase = max(1, $total - $empty);
    $coverage = (int)round(($used / $coverageBase) * 100);

    return [
        'summary' => [
            'total' => $total,
            'used' => $used,
            'unused' => $unused,
            'suspicious' => $suspicious,
            'empty' => $empty,
            'coverage' => $coverage,
        ],
        'items' => $items,
    ];
}

function cmp_collect_used_lines_from_tree(array $node, array &$usedMap): void {
    if (isset($node['label']) && is_string($node['label'])) {
        $label = trim($node['label']);
        if ($label !== '') {
            $usedMap[$label] = $node['type'] ?? 'nodo';
        }
    }

    if (isset($node['text_original']) && is_string($node['text_original'])) {
        $parts = preg_split('/\n+/u', $node['text_original']) ?: [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $usedMap[$part] = $node['type'] ?? 'texto';
            }
        }
    }

    if (isset($node['matches']) && is_array($node['matches'])) {
        foreach ($node['matches'] as $match) {
            if (!empty($match['source_line'])) {
                $usedMap[trim((string)$match['source_line'])] = 'partido';
            }
        }
    }

    if (!empty($node['children']) && is_array($node['children'])) {
        foreach ($node['children'] as $child) {
            if (is_array($child)) {
                cmp_collect_used_lines_from_tree($child, $usedMap);
            }
        }
    }
}

function cmp_guess_line_status(string $line): array {
    if (cmp_is_date_heading($line)) {
        return ['status' => 'suspicious', 'type' => 'posible_fecha'];
    }

    if (cmp_is_group_heading($line)) {
        return ['status' => 'suspicious', 'type' => 'posible_grupo'];
    }

    if (cmp_is_knockout_heading($line)) {
        return ['status' => 'suspicious', 'type' => 'posible_ronda'];
    }

    if (preg_match('/\b\d+\s*[-:]\s*\d+\b/u', $line)) {
        return ['status' => 'suspicious', 'type' => 'posible_partido'];
    }

    if (preg_match('/^\d+(\.| )/u', $line) || preg_match('/\bpts?\b/iu', $line)) {
        return ['status' => 'suspicious', 'type' => 'posible_tabla'];
    }

    return ['status' => 'unused', 'type' => 'no_usado'];
}