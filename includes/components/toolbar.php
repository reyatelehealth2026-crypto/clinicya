<?php
/**
 * Toolbar Component - Search + Filter chips + Bulk-action row
 *
 * Part of the Archetype A (List/CRUD) partial set.
 * Renders a card-style toolbar with search input, dropdown filters, filter chips,
 * and an optional bulk-action zone. Inputs all live in a single <form method="GET">
 * so the host page does not need extra plumbing.
 *
 * @package UIRollout
 * @version 1.0.0
 */

/**
 * Render a toolbar.
 *
 * @param array $options Configuration:
 *   - action          (string) Form action URL (default current page)
 *   - method          (string) 'GET' (default) or 'POST'
 *   - hiddenFields    (array<string,string>) Hidden inputs to preserve params (e.g. tab, sort, dir)
 *   - search          (array|null) ['name' => string, 'value' => string, 'placeholder' => string]
 *   - selects         (array) Each item: ['name' => string, 'value' => string, 'options' => [['value'=>..,'label'=>..,'selected'=>bool]], 'placeholder' => string]
 *   - chips           (array) Each item: ['href' => string, 'icon' => string, 'label' => string, 'active' => bool, 'tone' => 'primary'|'success'|'warning'|'danger'|'neutral']
 *   - chipGroupLabel  (string|null) Optional caption shown before the chips
 *   - resetHref       (string|null) Optional "clear filters" link URL
 *   - meta            (string|null) Right-aligned meta text (e.g. "Showing 1-50 of 200")
 *   - bulkInfo        (string|null) Text shown in the bulk-action bar (e.g. "<span id='selectedCount'>0</span> รายการที่เลือก:")
 *   - bulkActions     (array) Each: ['label' => string, 'icon' => string, 'onclick' => string, 'tone' => 'success'|'danger'|'neutral']
 *   - bulkContainerId (string|null) DOM id wrapping the bulk-action zone (host toggles .hidden)
 *
 * @return string HTML output
 */
function renderToolbar($options = []) {
    $action = $options['action'] ?? '';
    $method = strtoupper($options['method'] ?? 'GET');
    $hidden = $options['hiddenFields'] ?? [];
    $search = $options['search'] ?? null;
    $selects = $options['selects'] ?? [];
    $chips = $options['chips'] ?? [];
    $chipGroupLabel = $options['chipGroupLabel'] ?? null;
    $resetHref = $options['resetHref'] ?? null;
    $meta = $options['meta'] ?? null;
    $bulkInfo = $options['bulkInfo'] ?? null;
    $bulkActions = $options['bulkActions'] ?? [];
    $bulkContainerId = $options['bulkContainerId'] ?? null;

    $html = '<div class="toolbar">';

    // Top row: form (search + selects) + meta on right
    $hasFormFields = $search || !empty($selects) || !empty($hidden);
    if ($hasFormFields) {
        $html .= '<form class="toolbar-form" method="' . htmlspecialchars($method) . '"';
        if ($action !== '') {
            $html .= ' action="' . htmlspecialchars($action) . '"';
        }
        $html .= '>';

        foreach ($hidden as $k => $v) {
            $html .= '<input type="hidden" name="' . htmlspecialchars((string) $k) . '" value="' . htmlspecialchars((string) $v) . '">';
        }

        if ($search) {
            $name = htmlspecialchars((string) ($search['name'] ?? 'search'));
            $value = htmlspecialchars((string) ($search['value'] ?? ''));
            $placeholder = htmlspecialchars((string) ($search['placeholder'] ?? 'ค้นหา…'));
            $html .= '<div class="toolbar-search">';
            $html .= '<i class="fas fa-search toolbar-search-icon"></i>';
            $html .= '<input type="text" name="' . $name . '" value="' . $value . '" placeholder="' . $placeholder . '" class="toolbar-search-input">';
            $html .= '</div>';
        }

        foreach ($selects as $sel) {
            $name = htmlspecialchars((string) ($sel['name'] ?? ''));
            $current = (string) ($sel['value'] ?? '');
            $placeholder = $sel['placeholder'] ?? null;
            $html .= '<select name="' . $name . '" class="toolbar-select" onchange="this.form.submit()">';
            if ($placeholder !== null) {
                $html .= '<option value="">' . htmlspecialchars((string) $placeholder) . '</option>';
            }
            foreach (($sel['options'] ?? []) as $opt) {
                $oVal = (string) ($opt['value'] ?? '');
                $oLab = (string) ($opt['label'] ?? $oVal);
                $isSel = !empty($opt['selected']) || ($oVal === $current && $oVal !== '');
                $html .= '<option value="' . htmlspecialchars($oVal) . '"' . ($isSel ? ' selected' : '') . '>' . htmlspecialchars($oLab) . '</option>';
            }
            $html .= '</select>';
        }

        $html .= '<button type="submit" class="toolbar-submit" aria-label="Search"><i class="fas fa-search"></i></button>';

        if ($resetHref) {
            $html .= '<a href="' . htmlspecialchars($resetHref) . '" class="toolbar-reset" aria-label="Clear filters"><i class="fas fa-times"></i></a>';
        }

        $html .= '</form>';
    }

    if ($meta !== null) {
        $html .= '<div class="toolbar-meta">' . $meta . '</div>';
    }

    // Chips row
    if (!empty($chips)) {
        $html .= '<div class="toolbar-chips">';
        if ($chipGroupLabel) {
            $html .= '<span class="toolbar-chip-label">' . htmlspecialchars((string) $chipGroupLabel) . '</span>';
        }
        foreach ($chips as $chip) {
            $href = htmlspecialchars((string) ($chip['href'] ?? '#'));
            $label = htmlspecialchars((string) ($chip['label'] ?? ''));
            $icon = $chip['icon'] ?? '';
            $tone = $chip['tone'] ?? 'neutral';
            $active = !empty($chip['active']);
            $classes = 'toolbar-chip toolbar-chip-' . htmlspecialchars($tone);
            if ($active) {
                $classes .= ' toolbar-chip-active';
            }
            $iconHtml = $icon ? '<i class="' . htmlspecialchars($icon) . '"></i>' : '';
            $html .= '<a href="' . $href . '" class="' . $classes . '">' . $iconHtml . '<span>' . $label . '</span></a>';
        }
        $html .= '</div>';
    }

    // Bulk-action zone
    if ($bulkInfo !== null || !empty($bulkActions)) {
        $idAttr = $bulkContainerId ? ' id="' . htmlspecialchars($bulkContainerId) . '"' : '';
        $html .= '<div class="toolbar-bulk"' . $idAttr . '>';
        if ($bulkInfo !== null) {
            $html .= '<div class="toolbar-bulk-info">' . $bulkInfo . '</div>';
        }
        if (!empty($bulkActions)) {
            $html .= '<div class="toolbar-bulk-actions">';
            foreach ($bulkActions as $act) {
                $label = htmlspecialchars((string) ($act['label'] ?? ''));
                $icon = $act['icon'] ?? '';
                $tone = $act['tone'] ?? 'neutral';
                $onclick = $act['onclick'] ?? '';
                $iconHtml = $icon ? '<i class="' . htmlspecialchars($icon) . '"></i>' : '';
                $onclickAttr = $onclick !== '' ? ' onclick="' . htmlspecialchars($onclick) . '"' : '';
                $html .= '<button type="button" class="toolbar-bulk-btn toolbar-bulk-btn-' . htmlspecialchars($tone) . '"' . $onclickAttr . '>' . $iconHtml . '<span>' . $label . '</span></button>';
            }
            $html .= '</div>';
        }
        $html .= '</div>';
    }

    $html .= '</div>'; // /.toolbar
    return $html;
}

/**
 * Toolbar CSS — design-tokens-driven, with .dark overrides at the bottom.
 *
 * @return string <style>…</style>
 */
function getToolbarStyles() {
    return <<<CSS
<style>
/* Toolbar — uses design-tokens.css custom properties. */
.toolbar {
    background: #ffffff;
    border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-lg, 16px);
    padding: var(--space-3, 12px) var(--space-4, 16px);
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-3, 12px);
    margin-bottom: var(--space-4, 16px);
}

.toolbar-form {
    display: inline-flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-2, 8px);
    flex: 1 1 auto;
    min-width: 0;
}

.toolbar-search {
    position: relative;
    flex: 1 1 240px;
    min-width: 200px;
    max-width: 360px;
}

.toolbar-search-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--color-slate-400);
    font-size: var(--text-sm, 14px);
    pointer-events: none;
}

.toolbar-search-input {
    width: 100%;
    height: 40px;
    padding: 0 12px 0 36px;
    border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-md, 12px);
    background: var(--color-slate-50);
    font-size: var(--text-sm, 14px);
    color: var(--color-dark-800);
    transition: all var(--transition-fast, 150ms ease);
}

.toolbar-search-input::placeholder {
    color: var(--color-slate-400);
}

.toolbar-search-input:focus {
    outline: none;
    background: #ffffff;
    border-color: var(--color-primary-400);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12);
}

.toolbar-select {
    height: 40px;
    padding: 0 32px 0 12px;
    border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-md, 12px);
    background: var(--color-slate-50);
    color: var(--color-dark-800);
    font-size: var(--text-sm, 14px);
    cursor: pointer;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    transition: all var(--transition-fast, 150ms ease);
}

.toolbar-select:focus {
    outline: none;
    border-color: var(--color-primary-400);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.12);
}

.toolbar-submit,
.toolbar-reset {
    height: 40px;
    min-width: 40px;
    padding: 0 12px;
    border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-md, 12px);
    background: var(--color-slate-50);
    color: var(--color-dark-700);
    font-size: var(--text-sm, 14px);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    transition: all var(--transition-fast, 150ms ease);
}

.toolbar-submit:hover,
.toolbar-reset:hover {
    background: #ffffff;
    border-color: var(--color-primary-300);
    color: var(--color-primary-600);
}

.toolbar-meta {
    font-size: var(--text-xs, 12px);
    color: var(--color-dark-500);
    margin-left: auto;
    white-space: nowrap;
}

/* Chip row */
.toolbar-chips {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-2, 8px);
    width: 100%;
    padding-top: var(--space-2, 8px);
    border-top: 1px dashed var(--color-slate-200);
}

.toolbar-chip-label {
    font-size: var(--text-xs, 12px);
    font-weight: 500;
    color: var(--color-dark-500);
    margin-right: 4px;
}

.toolbar-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: var(--radius-full, 9999px);
    font-size: var(--text-xs, 12px);
    font-weight: 500;
    text-decoration: none;
    border: 1px solid transparent;
    transition: all var(--transition-fast, 150ms ease);
    white-space: nowrap;
}

.toolbar-chip-neutral {
    background: var(--color-slate-100);
    color: var(--color-dark-700);
}

.toolbar-chip-neutral:hover {
    background: var(--color-slate-200);
}

.toolbar-chip-primary {
    background: var(--color-primary-50);
    color: var(--color-primary-700);
}

.toolbar-chip-primary:hover {
    background: var(--color-primary-100);
}

.toolbar-chip-success {
    background: var(--color-emerald-50);
    color: var(--color-emerald-700);
}

.toolbar-chip-success:hover {
    background: var(--color-emerald-100);
}

.toolbar-chip-warning {
    background: var(--color-amber-50);
    color: var(--color-amber-700);
}

.toolbar-chip-warning:hover {
    background: var(--color-amber-100);
}

.toolbar-chip-danger {
    background: var(--color-rose-50);
    color: var(--color-rose-700);
}

.toolbar-chip-danger:hover {
    background: var(--color-rose-100);
}

.toolbar-chip-active.toolbar-chip-neutral {
    background: var(--color-dark-700);
    color: #ffffff;
}

.toolbar-chip-active.toolbar-chip-primary {
    background: var(--color-primary-600);
    color: #ffffff;
}

.toolbar-chip-active.toolbar-chip-success {
    background: var(--color-emerald-600);
    color: #ffffff;
}

.toolbar-chip-active.toolbar-chip-warning {
    background: var(--color-amber-500);
    color: #ffffff;
}

.toolbar-chip-active.toolbar-chip-danger {
    background: var(--color-rose-600);
    color: #ffffff;
}

/* Bulk-action zone */
.toolbar-bulk {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-2, 8px);
    width: 100%;
    padding-top: var(--space-2, 8px);
    border-top: 1px dashed var(--color-slate-200);
}

.toolbar-bulk.hidden {
    display: none !important;
}

.toolbar-bulk-info {
    font-size: var(--text-sm, 14px);
    color: var(--color-dark-500);
}

.toolbar-bulk-actions {
    display: inline-flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--space-2, 8px);
}

.toolbar-bulk-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: var(--radius-md, 12px);
    font-size: var(--text-xs, 12px);
    font-weight: 600;
    cursor: pointer;
    border: 1px solid transparent;
    transition: all var(--transition-fast, 150ms ease);
}

.toolbar-bulk-btn-neutral {
    background: var(--color-slate-100);
    color: var(--color-dark-800);
}

.toolbar-bulk-btn-neutral:hover {
    background: var(--color-slate-200);
}

.toolbar-bulk-btn-success {
    background: var(--color-emerald-100);
    color: var(--color-emerald-700);
}

.toolbar-bulk-btn-success:hover {
    background: var(--color-emerald-200);
}

.toolbar-bulk-btn-warning {
    background: var(--color-amber-100);
    color: var(--color-amber-700);
}

.toolbar-bulk-btn-warning:hover {
    background: var(--color-amber-200);
}

.toolbar-bulk-btn-danger {
    background: var(--color-rose-500);
    color: #ffffff;
}

.toolbar-bulk-btn-danger:hover {
    background: var(--color-rose-600);
}

@media (max-width: 640px) {
    .toolbar-search {
        flex-basis: 100%;
        max-width: none;
    }
    .toolbar-meta {
        margin-left: 0;
        width: 100%;
    }
}

/* ========================================
   DARK MODE OVERRIDES
   ======================================== */
.dark .toolbar {
    background: var(--color-dark-800);
    border-color: var(--color-dark-700);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.25);
}

.dark .toolbar-search-input,
.dark .toolbar-select,
.dark .toolbar-submit,
.dark .toolbar-reset {
    background: var(--color-dark-900);
    border-color: var(--color-dark-700);
    color: var(--color-slate-100);
}

.dark .toolbar-search-input::placeholder {
    color: var(--color-dark-500);
}

.dark .toolbar-search-input:focus,
.dark .toolbar-select:focus {
    border-color: var(--color-primary-400);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.2);
}

.dark .toolbar-submit:hover,
.dark .toolbar-reset:hover {
    background: var(--color-dark-700);
    color: var(--color-primary-300);
    border-color: var(--color-primary-500);
}

.dark .toolbar-search-icon {
    color: var(--color-dark-500);
}

.dark .toolbar-select {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2394a3b8' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
}

.dark .toolbar-meta,
.dark .toolbar-chip-label,
.dark .toolbar-bulk-info {
    color: var(--color-slate-400);
}

.dark .toolbar-chips,
.dark .toolbar-bulk {
    border-top-color: var(--color-dark-700);
}

.dark .toolbar-chip-neutral {
    background: var(--color-dark-700);
    color: var(--color-slate-300);
}

.dark .toolbar-chip-neutral:hover {
    background: var(--color-dark-600);
}

.dark .toolbar-chip-primary {
    background: rgba(99, 102, 241, 0.15);
    color: var(--color-primary-300);
}

.dark .toolbar-chip-success {
    background: rgba(16, 185, 129, 0.15);
    color: var(--color-emerald-300);
}

.dark .toolbar-chip-warning {
    background: rgba(245, 158, 11, 0.15);
    color: var(--color-amber-300);
}

.dark .toolbar-chip-danger {
    background: rgba(244, 63, 94, 0.15);
    color: var(--color-rose-300);
}

.dark .toolbar-bulk-btn-neutral {
    background: var(--color-dark-700);
    color: var(--color-slate-100);
}

.dark .toolbar-bulk-btn-neutral:hover {
    background: var(--color-dark-600);
}

.dark .toolbar-bulk-btn-success {
    background: rgba(16, 185, 129, 0.18);
    color: var(--color-emerald-300);
}

.dark .toolbar-bulk-btn-warning {
    background: rgba(245, 158, 11, 0.18);
    color: var(--color-amber-300);
}
</style>
CSS;
}
