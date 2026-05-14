<?php
/**
 * Page Header Component - Title + Subtitle + Breadcrumb + Primary Action
 *
 * Part of the Archetype A (List/CRUD) partial set.
 * Follows the tabs.php pattern: render function + companion getXxxStyles().
 *
 * @package UIRollout
 * @version 1.0.0
 */

/**
 * Render a page header with title, optional subtitle, breadcrumb, and primary action button.
 *
 * @param string $title       Page title (HTML-escaped internally; pass plain text)
 * @param string $subtitle    Optional short description below title
 * @param array|null $primaryAction Optional ['label' => string, 'icon' => string, 'href' => string, 'onclick' => string, 'type' => 'button'|'link', 'variant' => 'primary'|'success']
 * @param array $breadcrumb   Optional list of ['label' => string, 'href' => string|null]
 * @return string HTML output
 */
function renderPageHeader($title, $subtitle = '', $primaryAction = null, $breadcrumb = []) {
    $titleEsc = htmlspecialchars((string) $title);
    $subtitleEsc = htmlspecialchars((string) $subtitle);

    $html = '<div class="page-header">';

    // Breadcrumb
    if (!empty($breadcrumb)) {
        $html .= '<nav class="page-header-breadcrumb" aria-label="breadcrumb">';
        $last = count($breadcrumb) - 1;
        foreach ($breadcrumb as $i => $crumb) {
            $label = htmlspecialchars((string) ($crumb['label'] ?? ''));
            $href = $crumb['href'] ?? null;
            if ($href && $i !== $last) {
                $html .= '<a href="' . htmlspecialchars($href) . '" class="page-header-crumb">' . $label . '</a>';
            } else {
                $html .= '<span class="page-header-crumb page-header-crumb-current">' . $label . '</span>';
            }
            if ($i !== $last) {
                $html .= '<span class="page-header-crumb-sep"><i class="fas fa-chevron-right"></i></span>';
            }
        }
        $html .= '</nav>';
    }

    // Title row (title + subtitle on left, action on right)
    $html .= '<div class="page-header-row">';
    $html .= '<div class="page-header-text">';
    $html .= '<h1 class="page-header-title">' . $titleEsc . '</h1>';
    if ($subtitleEsc !== '') {
        $html .= '<p class="page-header-subtitle">' . $subtitleEsc . '</p>';
    }
    $html .= '</div>';

    if ($primaryAction) {
        $label = htmlspecialchars((string) ($primaryAction['label'] ?? ''));
        $icon = $primaryAction['icon'] ?? '';
        $variant = $primaryAction['variant'] ?? 'primary';
        $iconHtml = $icon ? '<i class="' . htmlspecialchars($icon) . '"></i>' : '';
        $btnClass = 'page-header-action page-header-action-' . htmlspecialchars($variant);

        $type = $primaryAction['type'] ?? 'button';
        if ($type === 'link' && !empty($primaryAction['href'])) {
            $href = htmlspecialchars($primaryAction['href']);
            $html .= '<a href="' . $href . '" class="' . $btnClass . '">' . $iconHtml . '<span>' . $label . '</span></a>';
        } else {
            $onclick = !empty($primaryAction['onclick']) ? ' onclick="' . htmlspecialchars($primaryAction['onclick']) . '"' : '';
            $html .= '<button type="button" class="' . $btnClass . '"' . $onclick . '>' . $iconHtml . '<span>' . $label . '</span></button>';
        }
    }

    $html .= '</div>'; // /.page-header-row
    $html .= '</div>'; // /.page-header

    return $html;
}

/**
 * Page header CSS — design-tokens-driven, includes .dark overrides at the bottom.
 *
 * @return string <style>…</style>
 */
function getPageHeaderStyles() {
    return <<<CSS
<style>
/* Page Header — uses design-tokens.css custom properties. */
.page-header {
    margin-bottom: var(--space-6, 24px);
}

.page-header-breadcrumb {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    font-size: var(--text-xs, 12px);
    color: var(--color-dark-500);
    margin-bottom: var(--space-3, 12px);
}

.page-header-crumb {
    color: var(--color-dark-500);
    text-decoration: none;
    transition: color var(--transition-fast, 150ms ease);
}

.page-header-crumb:hover {
    color: var(--color-primary-600);
}

.page-header-crumb-current {
    color: var(--color-dark-800);
    font-weight: 500;
}

.page-header-crumb-sep {
    font-size: 10px;
    color: var(--color-slate-300);
}

.page-header-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-4, 16px);
}

.page-header-text {
    flex: 1 1 auto;
    min-width: 0;
}

.page-header-title {
    font-family: var(--font-sans);
    font-size: var(--text-2xl, 24px);
    font-weight: 700;
    color: var(--color-dark-800);
    line-height: 1.2;
    margin: 0;
    letter-spacing: -0.01em;
}

.page-header-subtitle {
    font-size: var(--text-sm, 14px);
    color: var(--color-dark-500);
    margin: var(--space-1, 4px) 0 0 0;
    line-height: 1.45;
}

.page-header-action {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2, 8px);
    padding: 10px var(--space-4, 16px);
    border-radius: var(--radius-md, 12px);
    font-size: var(--text-sm, 14px);
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    border: none;
    transition: all var(--transition-fast, 150ms ease);
    white-space: nowrap;
}

.page-header-action-primary {
    background: var(--color-primary-600);
    color: #ffffff;
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
}

.page-header-action-primary:hover {
    background: var(--color-primary-700);
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(79, 70, 229, 0.32);
}

.page-header-action-success {
    background: var(--color-emerald-500);
    color: #ffffff;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
}

.page-header-action-success:hover {
    background: var(--color-emerald-600);
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(16, 185, 129, 0.32);
}

@media (max-width: 640px) {
    .page-header-title {
        font-size: var(--text-xl, 20px);
    }
    .page-header-row {
        gap: var(--space-3, 12px);
    }
    .page-header-action {
        width: 100%;
        justify-content: center;
    }
}

/* ========================================
   DARK MODE OVERRIDES
   Triggered by `.dark` body class.
   ======================================== */
.dark .page-header-title {
    color: var(--color-slate-100);
}

.dark .page-header-subtitle,
.dark .page-header-crumb,
.dark .page-header-breadcrumb {
    color: var(--color-slate-400);
}

.dark .page-header-crumb-current {
    color: var(--color-slate-100);
}

.dark .page-header-crumb:hover {
    color: var(--color-primary-400);
}

.dark .page-header-crumb-sep {
    color: var(--color-dark-600);
}

.dark .page-header-action-primary {
    background: var(--color-primary-500);
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.35);
}

.dark .page-header-action-primary:hover {
    background: var(--color-primary-600);
}

.dark .page-header-action-success {
    background: var(--color-emerald-500);
}

.dark .page-header-action-success:hover {
    background: var(--color-emerald-600);
}
</style>
CSS;
}
