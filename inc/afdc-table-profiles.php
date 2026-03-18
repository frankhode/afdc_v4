<?php
/**
 * AFDC - Table Profiles
 *
 * Perfiles de tabla reutilizables para el motor de tablas.
 *
 * Requiere helpers: h() (bootstrap)
 */

declare(strict_types=1);

// -------------------- helpers --------------------

function afdc_fmt_fecha(?string $yyyymmdd): string {
    $s = trim((string)$yyyymmdd);
    if ($s === '' || strlen($s) < 8) return $s;
    return substr($s, 0, 4) . '-' . substr($s, 4, 2) . '-' . substr($s, 6, 2);
}

function afdc_opac_url(string $sys): string {
    return 'https://catalogo.bn.gov.ar/F/?func=direct&doc_number=' . rawurlencode($sys);
}

function afdc_render_sysbn(array $r): string {
    $sys = trim((string)($r['sys'] ?? ''));
    if ($sys === '') return '<span class="afdc-muted">—</span>';
    $url = afdc_opac_url($sys);
    return '<a class="nowrap" href="' . h($url) . '" target="_blank" rel="noopener">' . h($sys) . '</a>';
}

function afdc_render_ver_digital(array $r): string {
    $barcode = trim((string)($r['barcode'] ?? ''));
    $cnt = (int)($r['digital_count'] ?? 0);
    $hasAlta = (int)($r['has_alta'] ?? 0);

    if ($barcode === '') return '<span class="afdc-muted">—</span>';

    if ($cnt > 0) {
        $url = 'ver_digital.php?barcode=' . rawurlencode($barcode) . '&i=0';
        return '<a class="nowrap" href="' . h($url) . '" target="_blank" rel="noopener">Ver (' . (int)$cnt . ')</a>';
    }

    if ($hasAlta) return '<span class="small">Falta baja</span>';

    return '<span class="afdc-muted">—</span>';
}

function afdc_render_materias(array $r): string {
    $m = trim((string)($r['materias'] ?? ''));
    if ($m === '') return '<span class="afdc-muted">—</span>';

    $parts = preg_split('/\s*\|\s*/', $m) ?: [];
    $parts = array_values(array_filter(array_map('trim', $parts), fn($x) => $x !== ''));
    if (!$parts) return '<span class="afdc-muted">—</span>';

    $out = [];
      foreach ($parts as $mat) {
          $qs = http_build_query([
             // Una materia puede ser 600/610/611/630/650/651/655.
             // Por eso, al clickear una pill buscamos en "Todas (6XX)".
             'field' => ['Materias6XX'],
              'term'  => [$mat],
              'page'  => 1,
          ]);
          $url = 'buscador_avanzado.php?' . $qs;
          $out[] = '<a class="afdc-pill afdc-pill-link" href="' . h($url) . '" title="Buscar por esta materia">' . h($mat) . '</a>';
      }

      return implode(' ', $out);
  }


function afdc_render_fecha(array $r): string {
    return h(afdc_fmt_fecha((string)($r['fecha'] ?? '')));
}

function afdc_render_link_or_text(string $keyText, string $keyUrl): callable {
    return function(array $r) use ($keyText, $keyUrl): string {
        $txt = trim((string)($r[$keyText] ?? ''));
        $url = trim((string)($r[$keyUrl] ?? ''));
        if ($txt === '') return '<span class="afdc-muted">—</span>';
        if ($url === '') return h($txt);
        return '<a href="' . h($url) . '">' . h($txt) . '</a>';
    };
}

function afdc_render_bool_badge(string $key): callable {
    return function(array $r) use ($key): string {
        $v = $r[$key] ?? 0;
        $yes = ($v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'on');
        $cls = $yes ? 'afdc-badge yes' : 'afdc-badge no';
        $lab = $yes ? 'Sí' : 'No';
        return '<span class="' . $cls . '">' . $lab . '</span>';
    };
}

// -------------------- profiles --------------------

function afdc_table_profiles(): array {
    return [
        'buscador' => [
            'tools' => [
                'remote_filter' => true,
              'csv' => true,
          ],
         'help_toolbar' => 'Tip: clic en Materias filtra por materias (6XX) • SYS abre OPAC • Ver digital abre visor',
          'columns' => [
              ['label' => 'SYS BN', 'key' => 'sys', 'sort' => 'num', 'class' => 'nowrap', 'render' => 'afdc_render_sysbn'],
              ['label' => 'Ver digital', 'key' => 'digital_count', 'class' => 'nowrap', 'render' => 'afdc_render_ver_digital'],
              ['label' => 'Título', 'key' => 'titulo', 'sort' => 'text', 'default_sort' => 'asc'],
              ['label' => 'Materias', 'key' => 'materias', 'sort' => 'text', 'render' => 'afdc_render_materias'],
              ['label' => 'Barcode', 'key' => 'barcode', 'sort' => 'text', 'class' => 'nowrap'],
              ['label' => 'Fecha', 'key' => 'fecha', 'sort' => 'date', 'class' => 'nowrap', 'render' => 'afdc_render_fecha'],
          ],
      ],

        'partidos' => [
            'tools' => [
                'remote_filter' => true,
                'csv' => true,
            ],
            'help_toolbar' => 'Tip: filtrá por equipos, estadio o palabras del título',
            'columns' => [
                ['label' => 'SYS BN', 'key' => 'sys', 'sort' => 'num', 'class' => 'nowrap', 'render' => 'afdc_render_sysbn'],
                ['label' => 'Ver digital', 'key' => 'digital_count', 'class' => 'nowrap', 'render' => 'afdc_render_ver_digital'],
                ['label' => 'Título', 'key' => 'titulo', 'sort' => 'text', 'default_sort' => 'asc'],
                ['label' => 'Equipo 1', 'key' => 'equipo_1', 'sort' => 'text', 'render' => afdc_render_link_or_text('equipo_1', 'equipo_1_url')],
                ['label' => 'Equipo 2', 'key' => 'equipo_2', 'sort' => 'text', 'render' => afdc_render_link_or_text('equipo_2', 'equipo_2_url')],
                ['label' => 'Estadio', 'key' => 'estadio', 'sort' => 'text'],
            ],
        ],

        'campeonatos' => [
            'tools' => [
                'remote_filter' => false,
                'csv' => true,
            ],
            'columns' => [
                ['label' => 'Campeonato/Equipo', 'key' => 'label_principal', 'sort' => 'text', 'render' => afdc_render_link_or_text('label_principal', 'label_url')],
                ['label' => 'Año', 'key' => 'anio', 'sort' => 'num', 'class' => 'nowrap'],
                ['label' => 'Sobres', 'key' => 'sobres_count', 'sort' => 'num', 'class' => 'nowrap'],
                ['label' => 'Imágenes', 'key' => 'imagenes_count', 'sort' => 'num', 'class' => 'nowrap'],
                ['label' => 'Tiene digital', 'key' => 'tiene_digital', 'class' => 'nowrap', 'render' => afdc_render_bool_badge('tiene_digital')],
                ['label' => '', 'key' => 'accion_label', 'class' => 'nowrap col-action', 'render' => afdc_render_link_or_text('accion_label', 'accion_url')],
            ],
        ],
    ];
}
