<?php
/**
 * Empty State Component - Icon + Heading + Sub + optional CTA
 *
 * Part of the Archetype A (List/CRUD) partial set.
 * Used when a list / table has zero rows.
 *
 * @package UIRollout
 * @version 1.0.0
 */

/**
 * Render an empty-state block.
 *
 * @param string      $icon   Font Awesome class (e.g. 'fas fa-box-open') OR full HTML
 * @param string      $heading Main heading text
 * @param string      $sub     Optional sub-text
 * @param array|null  $cta     Optional ['label' => string, 'icon' => string, 'href' => string, 'onclick' => string, 'type' => 'button'|'link']
 * @return string HTML output
 */
function renderEmptyState($icon, $heading, $sub = '', $cta = null) {
    $headingEsc = htmlspecialchars((string) $heading);
    $subEsc = htmlspecialchars((string) $sub);

    // Icon rendering: if string starts with '<', treat as raw HTML; else assume FA class.
    $iconHtml = (is_string($icon) && strlen($icon) > 0 && $icon[0] === '<')
        ? $icon
        : '<i class="' . htmlspecialchars((string) $icon) . '"></i>';

    $html = '<div class="empty-state">';
    $html .= '<div class="empty-state-icon">' . $iconHtml . '</div>';
    $html .= '<div class="empty-state-heading">' . $headingEsc . '</div>';
    if ($subEsc !== '') {
        $html .= '<div class="empty-state-sub">' . $subEsc . '</div>';
    }

    if ($cta) {
        $label = htmlspecialchars((string) ($cta['label'] ?? ''));
        $ctaIcon = $cta['icon'] ?? '';
        $iconNode = $ctaIcon ? '<i class="' . htmlspecialchars($ctaIcon) . '"></i>' : '';
        $type = $cta['type'] ?? 'button';
        if ($type === 'link' && !empty($cta['href'])) {
            $href = htmlspecialchars($cta['href']);
            $html .= '<a href="' . $href . '" class="empty-state-cta">' . $iconNode . '<span>' . $label . '</span></a>';
        } else {
            $onclick = !empty($cta['onclick']) ? ' onclick="' . htmlspecialchars($cta['onclick']) . '"' : '';
            $html .= '<button type="button" class="empty-state-cta"' . $onclick . '>' . $iconNode . '<span>' . $label . '</span></button>';
        }
    }

    $html .= '</div>';
    return $html;
}

/**
 * Empty-state CSS — design-tokens-driven, includes .dark overrides at the bottom.
 *
 * @return string <style>…</style>
 */
function getEmptyStateStyles() {
    return <<<CSS
<style>
/* Empty State — uses design-tokens.css custom properties. */
.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: var(--space-12, 48px) var(--space-6, 24px);
    gap: var(--space-3, 12px);
}

.empty-state-icon {
    width: 72px;
    height: 72px;
    border-radius: var(--radius-full, 9999px);
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-slate-100);
    color: var(--color-slate-400);
    font-size: 28px;
    margin-bottom: var(--space-2, 8px);
}

.empty-state-heading {
    font-size: var(--text-base, 16px);
    font-weight: 600;
    color: var(--color-dark-800);
    margin: 0;
}

.empty-state-sub {
    font-size: var(--text-sm, 14px);
    color: var(--color-dark-500);
    max-width: 380px;
    line-height: 1.5;
    margin: 0;
}

.empty-state-cta {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2, 8px);
    margin-top: var(--space-3, 12px);
    padding: 10px var(--space-4, 16px);
    border-radius: var(--radius-md, 12px);
    font-size: var(--text-sm, 14px);
    font-weight: 600;
    background: var(--color-primary-600);
    color: #ffffff;
    text-decoration: none;
    border: none;
    cursor: pointer;
    transition: all var(--transition-fast, 150ms ease);
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.22);
}

.empty-state-cta:hover {
    background: var(--color-primary-700);
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(79, 70, 229, 0.3);
}

/* ========================================
   DARK MODE OVERRIDES
   ======================================== */
.dark .empty-state-icon {
    background: var(--color-dark-700);
    color: var(--color-slate-400);
}

.dark .empty-state-heading {
    color: var(--color-slate-100);
}

.dark .empty-state-sub {
    color: var(--color-slate-400);
}

.dark .empty-state-cta {
    background: var(--color-primary-500);
}

.dark .empty-state-cta:hover {
    background: var(--color-primary-600);
}
</style>
CSS;
}
