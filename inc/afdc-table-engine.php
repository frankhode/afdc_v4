<?php
  /**
   * AFDC - Table Engine
   *
   * Motor reutilizable para renderizar tablas por "perfil".
   *
   * Features:
   * - Columnas configurables por perfil
   * - Toolbar compacta
!  * - Filtro remoto (refina en DB) y/o filtro cliente (oculta filas visibles)
   * - CSV (filas visibles)
   * - Sort cliente (solo la página actual)
   *
   * Requiere: h(), url_with() (inc/bootstrap.php)
   */

declare(strict_types=1);

require_once __DIR__ . '/afdc-table-profiles.php';

/**
 * Render principal.
 *
 * @param string $profile  Nombre del perfil (ver inc/afdc-table-profiles.php)
 * @param array  $rows     Filas (array de arrays asociativos)
 * @param array  $opts     Opciones:
 *   - filter_param (string) default 'filter'
 *   - filter_value (string) default $_GET[filter_param]
 *   - filter_label (string) etiqueta input filtro
 *   - filter_placeholder (string)
 *   - help_toolbar (string) override del help del perfil
 *   - table_id (string)
 */
function afdc_table_render(string $profile, array $rows, array $opts = []): void {
    $profiles = afdc_table_profiles();
    if (!isset($profiles[$profile])) {
        echo '<div class="error">Perfil de tabla inexistente: ' . h($profile) . '</div>';
        return;
    }

    $p = $profiles[$profile];
    $cols = $p['columns'] ?? [];
    $tools = $p['tools'] ?? [];
    $context = (string)($opts['context'] ?? '');

    $filterParam = (string)($opts['filter_param'] ?? 'filter');
    $filterValue = (string)($opts['filter_value'] ?? ($_GET[$filterParam] ?? ''));
    $filterValue = trim($filterValue);

    $filterLabel = (string)($opts['filter_label'] ?? 'Filtrar resultados');
    $filterPlaceholder = (string)($opts['filter_placeholder'] ?? 'Filtrar (sobre todos los resultados)');

    $help = (string)($opts['help_toolbar'] ?? ($p['help_toolbar'] ?? ''));

    $tableId = (string)($opts['table_id'] ?? '');
    $tableIdAttr = $tableId !== '' ? ' id="' . h($tableId) . '"' : '';

    $useRemote = !empty($tools['remote_filter']);
    $useCsv = !empty($tools['csv']);
    $remoteMode = (string)($opts['remote_mode'] ?? 'reload'); // reload|ajax
    $totalRows = (int)($opts['total_rows'] ?? count($rows));

    // Toolbar: por defecto se muestra si hay herramientas activas.
    // En modo AJAX, aunque el refinado deje 0 resultados, debe seguir visible para permitir borrar el filtro.
    // En buscador avanzado la toolbar vive fuera de la tabla, así que se puede forzar con opts['show_toolbar'].
    $showToolbarOpt = $opts['show_toolbar'] ?? null;
    if ($showToolbarOpt !== null) {
        $showToolbar = (bool)$showToolbarOpt;
    } else {
        // Toolbar por defecto (se puede forzar con opts['show_toolbar']).
        if ($useRemote && $remoteMode === 'ajax') {
            // En AJAX, aunque el refinado deje 0 resultados, la toolbar debe seguir visible
            // para poder borrar/modificar el filtro sin "perder" el input.
            $showToolbar = true;
        } else {
            $showToolbar = ($useRemote || $useCsv) && ($totalRows > 0 || !$useRemote);
        }
    
        if (array_key_exists('show_toolbar', $opts)) {
            $showToolbar = (bool)$opts['show_toolbar'];
        }
    }

    // Determinar default sort (si hay columna con default_sort)
    $defaultSortIdx = null;
    $defaultSortDir = null;
    foreach ($cols as $i => $c) {
        if (!empty($c['default_sort'])) {
            $defaultSortIdx = (int)$i;
            $defaultSortDir = (string)$c['default_sort'];
            break;
        }
    }

    echo '<div class="afdc-table-wrap" data-afdc-table'
        . ($context !== '' ? ' data-afdc-context="' . h($context) . '"' : '')
        . '>';

    // -------------------- tools --------------------
    if ($showToolbar) {
        echo '<div class="afdc-table-tools">';

        echo '<div class="left">';
        if ($useRemote) {
            echo '<label>' . h($filterLabel) . '</label>';
            if ($remoteMode === 'ajax') {
               echo '<div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">';
               echo '<input type="text" value="' . h($filterValue) . '" '
                   . 'placeholder="' . h($filterPlaceholder) . '" '
                   . 'data-afdc-filter data-afdc-remote-filter data-afdc-remote="ajax" '
                   . 'data-afdc-minlen="2" data-afdc-debounce="420" />';
               echo '<label style="display:flex; align-items:center; gap:8px; font-size:12px; color:rgba(255,255,255,.7);">';
               echo '<input type="checkbox" data-afdc-auto checked /> Auto';
               echo '</label>';
               echo '<button class="btn secondary" type="button" data-afdc-apply>Aplicar</button>';
               echo '<span class="small" data-afdc-status style="opacity:.7;"></span>';
               echo '</div>';
             } else {
               echo '<input type="text" value="' . h($filterValue) . '" '
                   . 'placeholder="' . h($filterPlaceholder) . '" '
                   . 'data-afdc-filter data-afdc-remote-filter '
                   . 'data-afdc-param="' . h($filterParam) . '" '
                   . 'data-afdc-reset-page="page" />';
             }
            echo '<div class="afdc-table-help" data-afdc-help>' . h($help) . '</div>';
        }
        echo '</div>';

        echo '<div class="right">';
        if ($useCsv) {
            echo '<button class="btn" type="button" data-afdc-csv>CSV</button>';
        }
        if ($useRemote) {
            echo '<button class="btn secondary" type="button" data-afdc-clear>Limpiar</button>';
        }
        echo '</div>';

        echo '</div>';
    }

    // -------------------- table --------------------
    echo '<table class="afdc-table" data-afdc-target' . $tableIdAttr . '>';
    echo '<thead><tr>';

    foreach ($cols as $i => $c) {
        $label = (string)($c['label'] ?? '');
        $sort = (string)($c['sort'] ?? '');
        $class = trim((string)($c['class'] ?? ''));

        $attrs = '';
        if ($sort !== '') $attrs .= ' data-sort="' . h($sort) . '"';
        if ($defaultSortIdx !== null && $i === $defaultSortIdx) {
            $attrs .= ' data-afdc-default="' . h($defaultSortDir ?: 'asc') . '"';
        }

        $clsAttr = $class !== '' ? ' class="' . h($class) . '"' : '';
        echo '<th' . $clsAttr . $attrs . '>' . h($label) . '</th>';
    }
    echo '</tr></thead>';

    echo '<tbody>';
    if (!$rows) {
        echo '<tr><td colspan="' . (int)count($cols) . '" class="small">Sin resultados.</td></tr>';
    } else {
        foreach ($rows as $r) {
            echo '<tr>';
            foreach ($cols as $c) {
                $key = (string)($c['key'] ?? '');
                $class = trim((string)($c['class'] ?? ''));

                $cell = '';
                $render = $c['render'] ?? null;
                if (is_string($render) && function_exists($render)) {
                    $cell = (string)$render($r);
                } elseif (is_callable($render)) {
                    $cell = (string)call_user_func($render, $r);
                } else {
                    $cell = h((string)($r[$key] ?? ''));
                }

                $clsAttr = $class !== '' ? ' class="' . h($class) . '"' : '';
                echo '<td' . $clsAttr . '>' . $cell . '</td>';
            }
            echo '</tr>';
        }
    }
    echo '</tbody>';
    echo '</table>';

    // Caption (útil para CSV + contador visible si se usa filtro cliente)
    echo '<div class="afdc-table-caption">';
    echo '<span>Filas: <strong data-afdc-visible-count>' . (int)max(0, count($rows)) . '</strong></span>';
    echo '</div>';

    echo '</div>'; // wrap
}
