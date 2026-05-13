<?php
/**
 * Data Table Component - Table shell with sticky header, sortable columns, row actions
 *
 * Part of the Archetype A (List/CRUD) partial set.
 * Renders a card-wrapped <table>. Cell content is emitted via callbacks supplied
 * in $columns so the host page keeps full control of business logic / formatting.
 *
 * @package UIRollout
 * @version 1.0.0
 */

/**
 * Render a data table.
 *
 * @param array $columns  Each item:
 *   - key     (string) Column id (used for sort param)
 *   - label   (string) Header label
 *   - align   (string|null) 'left' | 'center' | 'right' (default 'left')
 *   - width   (string|null) CSS width (e.g. '40px')
 *   - sortable(bool) Whether this column is sortable
 *   - sortHref(string|null) Pre-built sort URL (sortable links pre-built by host)
 *   - sortDir (string|null) 'ASC' | 'DESC' | null (current direction if active)
 *   - render  (callable) function(array $row): string — returns inner HTML for the cell
 *   - className (string|null) Extra CSS classes to apply to <td>
 *   - headerClassName (string|null) Extra CSS classes to apply to <th>
 *
 * @param array $rows     List of associative-array rows
 * @param array $options  Configuration:
 *   - emptyContent  (string) HTML to show when $rows is empty (e.g. renderEmptyState(...))
 *   - rowKey        (string) Row key for "data-id" attribute (default 'id')
 *   - rowClass      (callable|null) function(array $row): string — extra row classes
 *   - selectable    (bool) Whether to show a checkbox column at the left
 *   - selectAllId   (string) DOM id for the header checkbox (default 'selectAll')
 *   - rowCheckboxClass (string) Class on each row checkbox (default 'data-row-checkbox')
 *   - selectOnChange (string) Inline JS hook fired by row checkboxes (default '')
 *   - selectAllOnChange (string) Inline JS hook fired by header checkbox (default '')
 *
 * @return string HTML output
 */
function renderDataTable($columns, $rows, $options = []) {
    $emptyContent = $options['emptyContent'] ?? '<div style="padding:48px;text-align:center;color:var(--color-slate-400);">ไม่พบข้อมูล</div>';
    $rowKey = $options['rowKey'] ?? 'id';
    $rowClassFn = $options['rowClass'] ?? null;
    $selectable = !empty($options['selectable']);
    $selectAllId = $options['selectAllId'] ?? 'selectAll';
    $rowCbClass = $options['rowCheckboxClass'] ?? 'data-row-checkbox';
    $selectOnChange = $options['selectOnChange'] ?? '';
    $selectAllOnChange = $options['selectAllOnChange'] ?? '';

    $html = '<div class="data-table-card">';
    $html .= '<div class="data-table-scroll">';
    $html .= '<table class="data-table">';

    // Header
    $html .= '<thead><tr>';
    if ($selectable) {
        $onchangeAttr = $selectAllOnChange !== '' ? ' onchange="' . htmlspecialchars($selectAllOnChange) . '"' : '';
        $html .= '<th class="data-table-th data-table-th-checkbox" style="width:40px;">';
        $html .= '<input type="checkbox" id="' . htmlspecialchars($selectAllId) . '" class="data-table-checkbox"' . $onchangeAttr . '>';
        $html .= '</th>';
    }
    foreach ($columns as $col) {
        $align = $col['align'] ?? 'left';
        $width = $col['width'] ?? null;
        $extra = $col['headerClassName'] ?? '';
        $style = $width ? ' style="width:' . htmlspecialchars($width) . ';"' : '';
        $html .= '<th class="data-table-th data-table-th-' . htmlspecialchars($align) . ' ' . htmlspecialchars($extra) . '"' . $style . '>';

        $label = (string) ($col['label'] ?? '');
        if (!empty($col['sortable']) && !empty($col['sortHref'])) {
            $arrow = '';
            if (!empty($col['sortDir'])) {
                $arrow = $col['sortDir'] === 'ASC'
                    ? ' <i class="fas fa-sort-up data-table-sort-icon"></i>'
                    : ' <i class="fas fa-sort-down data-table-sort-icon"></i>';
            } else {
                $arrow = ' <i class="fas fa-sort data-table-sort-icon data-table-sort-idle"></i>';
            }
            $html .= '<a href="' . htmlspecialchars($col['sortHref']) . '" class="data-table-sort-link">' . htmlspecialchars($label) . $arrow . '</a>';
        } else {
            $html .= htmlspecialchars($label);
        }
        $html .= '</th>';
    }
    $html .= '</tr></thead>';

    // Body
    $html .= '<tbody>';
    if (empty($rows)) {
        $colspan = count($columns) + ($selectable ? 1 : 0);
        $html .= '<tr><td colspan="' . $colspan . '" class="data-table-empty-cell">' . $emptyContent . '</td></tr>';
    } else {
        foreach ($rows as $row) {
            $rowId = htmlspecialchars((string) ($row[$rowKey] ?? ''));
            $extraRowClass = $rowClassFn ? (string) call_user_func($rowClassFn, $row) : '';
            $html .= '<tr class="data-table-row ' . htmlspecialchars($extraRowClass) . '" data-id="' . $rowId . '">';

            if ($selectable) {
                $onchangeAttr = $selectOnChange !== '' ? ' onchange="' . htmlspecialchars($selectOnChange) . '"' : '';
                $html .= '<td class="data-table-td data-table-td-checkbox" onclick="event.stopPropagation()">';
                $html .= '<input type="checkbox" class="data-table-checkbox ' . htmlspecialchars($rowCbClass) . '" value="' . $rowId . '"' . $onchangeAttr . '>';
                $html .= '</td>';
            }

            foreach ($columns as $col) {
                $align = $col['align'] ?? 'left';
                $extra = $col['className'] ?? '';
                $renderFn = $col['render'] ?? null;
                $content = $renderFn ? (string) call_user_func($renderFn, $row) : '';
                $html .= '<td class="data-table-td data-table-td-' . htmlspecialchars($align) . ' ' . htmlspecialchars($extra) . '">' . $content . '</td>';
            }

            $html .= '</tr>';
        }
    }
    $html .= '</tbody>';

    $html .= '</table>';
    $html .= '</div>'; // /scroll
    $html .= '</div>'; // /card

    return $html;
}

/**
 * Data table CSS — design-tokens-driven, with .dark overrides at the bottom.
 *
 * @return string <style>…</style>
 */
function getDataTableStyles() {
    return <<<CSS
<style>
/* Data Table — uses design-tokens.css custom properties. */
.data-table-card {
    background: #ffffff;
    border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-lg, 16px);
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
    overflow: hidden;
}

.data-table-scroll {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: var(--text-sm, 14px);
}

.data-table-th {
    position: sticky;
    top: 0;
    z-index: 1;
    background: var(--color-slate-50);
    padding: 12px var(--space-3, 12px);
    text-align: left;
    font-size: var(--text-xs, 12px);
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--color-dark-500);
    border-bottom: 1px solid var(--color-slate-200);
    white-space: nowrap;
}

.data-table-th-left   { text-align: left; }
.data-table-th-center { text-align: center; }
.data-table-th-right  { text-align: right; }

.data-table-sort-link {
    color: inherit;
    text-decoration: none;
    transition: color var(--transition-fast, 150ms ease);
}

.data-table-sort-link:hover {
    color: var(--color-primary-600);
}

.data-table-sort-icon {
    margin-left: 4px;
    font-size: 10px;
}

.data-table-sort-idle {
    opacity: 0.4;
}

.data-table-td {
    padding: var(--space-3, 12px);
    color: var(--color-dark-800);
    border-bottom: 1px solid var(--color-slate-100);
    vertical-align: middle;
}

.data-table-td-left   { text-align: left; }
.data-table-td-center { text-align: center; }
.data-table-td-right  { text-align: right; }

.data-table-row {
    transition: background var(--transition-fast, 150ms ease);
}

.data-table-row:hover {
    background: var(--color-slate-50);
}

.data-table-row:last-child .data-table-td {
    border-bottom: none;
}

.data-table-th-checkbox,
.data-table-td-checkbox {
    text-align: center;
    width: 40px;
}

.data-table-checkbox {
    width: 16px;
    height: 16px;
    border-radius: 4px;
    cursor: pointer;
    accent-color: var(--color-primary-600);
}

.data-table-empty-cell {
    padding: 0 !important;
    background: #ffffff;
    border-bottom: none !important;
}

/* Row-action mini buttons (host uses .data-table-row-action class within render callbacks) */
.data-table-row-actions {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    white-space: nowrap;
}

.data-table-row-action {
    width: 30px;
    height: 30px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: transparent;
    border: 1px solid transparent;
    border-radius: var(--radius-sm, 8px);
    color: var(--color-dark-500);
    cursor: pointer;
    text-decoration: none;
    transition: all var(--transition-fast, 150ms ease);
    font-size: var(--text-sm, 14px);
}

.data-table-row-action:hover {
    background: var(--color-slate-100);
    color: var(--color-primary-600);
}

.data-table-row-action-danger:hover {
    background: var(--color-rose-50);
    color: var(--color-rose-600);
}

/* ========================================
   DARK MODE OVERRIDES
   ======================================== */
.dark .data-table-card {
    background: var(--color-dark-800);
    border-color: var(--color-dark-700);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.25);
}

.dark .data-table-th {
    background: var(--color-dark-900);
    color: var(--color-slate-400);
    border-bottom-color: var(--color-dark-700);
}

.dark .data-table-sort-link:hover {
    color: var(--color-primary-300);
}

.dark .data-table-td {
    color: var(--color-slate-100);
    border-bottom-color: var(--color-dark-700);
}

.dark .data-table-row:hover {
    background: var(--color-dark-700);
}

.dark .data-table-empty-cell {
    background: var(--color-dark-800);
}

.dark .data-table-row-action {
    color: var(--color-slate-400);
}

.dark .data-table-row-action:hover {
    background: var(--color-dark-700);
    color: var(--color-primary-300);
}

.dark .data-table-row-action-danger:hover {
    background: rgba(244, 63, 94, 0.15);
    color: var(--color-rose-300);
}

.dark .data-table-checkbox {
    accent-color: var(--color-primary-400);
}
</style>
CSS;
}
