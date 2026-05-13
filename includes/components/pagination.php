<?php
/**
 * Pagination Component - Page navigation strip
 *
 * Part of the Archetype A (List/CRUD) partial set.
 * Renders prev / numbered windows / next with current-page highlighted.
 *
 * @package UIRollout
 * @version 1.0.0
 */

/**
 * Render a pagination strip.
 *
 * @param int    $currentPage   1-based current page
 * @param int    $totalPages    Total page count
 * @param int    $perPage       Per-page size (for the info line)
 * @param string $baseUrl       URL fragment up to and including `?` and existing params, sans `page`.
 *                              The component appends `page=N` to it.
 *                              Example: "?tab=products&search=foo&"  (trailing & or ? must be supplied)
 * @param array  $options       Optional: ['total' => int, 'offset' => int, 'showInfo' => bool]
 * @return string HTML output
 */
function renderPagination($currentPage, $totalPages, $perPage, $baseUrl, $options = []) {
    $currentPage = max(1, (int) $currentPage);
    $totalPages = max(1, (int) $totalPages);
    $perPage = max(1, (int) $perPage);

    $showInfo = $options['showInfo'] ?? true;
    $total = $options['total'] ?? null;
    $offset = $options['offset'] ?? (($currentPage - 1) * $perPage);

    $html = '<div class="pagination-bar">';

    // Info line (left side)
    if ($showInfo && $total !== null) {
        $from = $total > 0 ? ($offset + 1) : 0;
        $to = min($offset + $perPage, $total);
        $html .= '<div class="pagination-info">';
        $html .= 'แสดง <b>' . number_format($from) . '</b>-<b>' . number_format($to) . '</b> จาก <b>' . number_format($total) . '</b> รายการ';
        $html .= '</div>';
    } else {
        $html .= '<div class="pagination-info">หน้า <b>' . number_format($currentPage) . '</b> / <b>' . number_format($totalPages) . '</b></div>';
    }

    // Nav (right side)
    if ($totalPages > 1) {
        $html .= '<div class="pagination-nav">';

        // Prev
        if ($currentPage > 1) {
            $html .= '<a href="' . htmlspecialchars($baseUrl . 'page=' . ($currentPage - 1)) . '" class="pagination-link" aria-label="Previous"><i class="fas fa-chevron-left"></i></a>';
        } else {
            $html .= '<span class="pagination-link pagination-link-disabled" aria-disabled="true"><i class="fas fa-chevron-left"></i></span>';
        }

        // Window: current ± 2
        $start = max(1, $currentPage - 2);
        $end = min($totalPages, $currentPage + 2);

        // First page (ellipsis if gap)
        if ($start > 1) {
            $html .= '<a href="' . htmlspecialchars($baseUrl . 'page=1') . '" class="pagination-link">1</a>';
            if ($start > 2) {
                $html .= '<span class="pagination-ellipsis">…</span>';
            }
        }

        for ($i = $start; $i <= $end; $i++) {
            if ($i === $currentPage) {
                $html .= '<span class="pagination-link pagination-link-current" aria-current="page">' . $i . '</span>';
            } else {
                $html .= '<a href="' . htmlspecialchars($baseUrl . 'page=' . $i) . '" class="pagination-link">' . $i . '</a>';
            }
        }

        if ($end < $totalPages) {
            if ($end < $totalPages - 1) {
                $html .= '<span class="pagination-ellipsis">…</span>';
            }
            $html .= '<a href="' . htmlspecialchars($baseUrl . 'page=' . $totalPages) . '" class="pagination-link">' . $totalPages . '</a>';
        }

        // Next
        if ($currentPage < $totalPages) {
            $html .= '<a href="' . htmlspecialchars($baseUrl . 'page=' . ($currentPage + 1)) . '" class="pagination-link" aria-label="Next"><i class="fas fa-chevron-right"></i></a>';
        } else {
            $html .= '<span class="pagination-link pagination-link-disabled" aria-disabled="true"><i class="fas fa-chevron-right"></i></span>';
        }

        $html .= '</div>';
    }

    $html .= '</div>';
    return $html;
}

/**
 * Pagination CSS — design-tokens-driven, with .dark overrides at the bottom.
 *
 * @return string <style>…</style>
 */
function getPaginationStyles() {
    return <<<CSS
<style>
/* Pagination — uses design-tokens.css custom properties. */
.pagination-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3, 12px);
    font-size: var(--text-sm, 14px);
    color: var(--color-dark-500);
}

.pagination-info {
    color: var(--color-dark-500);
}

.pagination-info b {
    color: var(--color-dark-800);
    font-weight: 600;
}

.pagination-nav {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.pagination-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 36px;
    height: 36px;
    padding: 0 10px;
    border-radius: var(--radius-sm, 8px);
    border: 1px solid var(--color-slate-200);
    background: #ffffff;
    color: var(--color-dark-700);
    font-size: var(--text-sm, 14px);
    font-weight: 500;
    text-decoration: none;
    transition: all var(--transition-fast, 150ms ease);
}

.pagination-link:hover {
    background: var(--color-slate-50);
    border-color: var(--color-primary-300);
    color: var(--color-primary-600);
}

.pagination-link-current,
.pagination-link-current:hover {
    background: var(--color-primary-600);
    border-color: var(--color-primary-600);
    color: #ffffff;
    box-shadow: 0 2px 6px rgba(79, 70, 229, 0.25);
    cursor: default;
}

.pagination-link-disabled,
.pagination-link-disabled:hover {
    background: var(--color-slate-50);
    color: var(--color-slate-300);
    border-color: var(--color-slate-200);
    cursor: not-allowed;
}

.pagination-ellipsis {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 24px;
    height: 36px;
    color: var(--color-slate-400);
}

@media (max-width: 640px) {
    .pagination-bar {
        flex-direction: column;
        align-items: stretch;
    }
    .pagination-nav {
        justify-content: center;
    }
}

/* ========================================
   DARK MODE OVERRIDES
   ======================================== */
.dark .pagination-info {
    color: var(--color-slate-400);
}

.dark .pagination-info b {
    color: var(--color-slate-100);
}

.dark .pagination-link {
    background: var(--color-dark-800);
    border-color: var(--color-dark-700);
    color: var(--color-slate-300);
}

.dark .pagination-link:hover {
    background: var(--color-dark-700);
    border-color: var(--color-primary-400);
    color: var(--color-primary-300);
}

.dark .pagination-link-current,
.dark .pagination-link-current:hover {
    background: var(--color-primary-500);
    border-color: var(--color-primary-500);
    color: #ffffff;
}

.dark .pagination-link-disabled,
.dark .pagination-link-disabled:hover {
    background: var(--color-dark-800);
    color: var(--color-dark-600);
    border-color: var(--color-dark-700);
}

.dark .pagination-ellipsis {
    color: var(--color-dark-600);
}
</style>
CSS;
}
