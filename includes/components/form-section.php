<?php
/**
 * Form Section Component
 * Sectioned form card with optional collapsible header
 *
 * @package Clinicya\Components
 * @version 1.0.0
 */

/**
 * Render a form section card.
 *
 * @param string $title       Section heading (Thai OK)
 * @param string $icon        Font Awesome class e.g. "fas fa-store"
 * @param string $description Optional sub-heading shown below title (pass '' to omit)
 * @param string $body        Inner HTML content (pre-rendered)
 * @param bool   $collapsible Whether the section can be toggled open/closed
 * @return string HTML
 */
function renderFormSection(string $title, string $icon, string $description, string $body, bool $collapsible = false): string {
    static $sectionIndex = 0;
    $sectionIndex++;
    $id = 'form-section-' . $sectionIndex;

    $titleHtml = '<span class="form-section-icon"><i class="' . htmlspecialchars($icon) . '"></i></span>'
               . '<span class="form-section-title">' . htmlspecialchars($title) . '</span>';

    if ($description !== '') {
        $titleHtml .= '<span class="form-section-desc">' . htmlspecialchars($description) . '</span>';
    }

    if ($collapsible) {
        $chevronHtml = '<i class="fas fa-chevron-down form-section-chevron" aria-hidden="true"></i>';
        $header = '<button type="button" class="form-section-header form-section-toggle"'
                . ' aria-expanded="true" aria-controls="' . $id . '-body"'
                . ' onclick="toggleFormSection(this)">'
                . $titleHtml
                . $chevronHtml
                . '</button>';
    } else {
        $header = '<div class="form-section-header">' . $titleHtml . '</div>';
    }

    return '<div class="form-section">'
         . $header
         . '<div class="form-section-body" id="' . $id . '-body">'
         . $body
         . '</div>'
         . '</div>';
}

/**
 * Inline CSS + JS for form-section component.
 * Call once per page inside <head> or before first use.
 *
 * @return string <style>+<script> block
 */
function getFormSectionStyles(): string {
    return <<<HTML
<style>
/* ==========================================
   Form Section — light + dark mode
   Relies on design-tokens.css custom props
   ========================================== */
.form-section {
    background: #ffffff;
    border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-lg);
    overflow: hidden;
    margin-bottom: var(--space-6);
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
    transition: box-shadow var(--transition-base);
}

.form-section:focus-within {
    box-shadow: 0 0 0 3px rgba(79,70,229,.1), 0 1px 4px rgba(0,0,0,.06);
}

.form-section-header {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    padding: var(--space-4) var(--space-6);
    background: var(--color-slate-50);
    border-bottom: 1px solid var(--color-slate-200);
    width: 100%;
    text-align: left;
    cursor: default;
}

button.form-section-header {
    cursor: pointer;
    border: none;
    transition: background var(--transition-fast);
}

button.form-section-header:hover {
    background: var(--color-slate-100);
}

.form-section-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: var(--radius-sm);
    background: var(--color-primary-50);
    color: var(--color-primary-600);
    font-size: var(--text-sm);
    flex-shrink: 0;
}

.form-section-title {
    font-size: var(--text-base);
    font-weight: 600;
    color: #1e293b;
    line-height: 1.3;
}

.form-section-desc {
    font-size: var(--text-sm);
    color: var(--color-dark-500);
    margin-left: auto;
    font-weight: 400;
}

.form-section-chevron {
    margin-left: auto;
    font-size: 12px;
    color: var(--color-dark-500);
    transition: transform var(--transition-base);
    flex-shrink: 0;
}

/* desc + chevron both present: chevron stays last */
.form-section-desc + .form-section-chevron {
    margin-left: var(--space-3);
}

button.form-section-toggle[aria-expanded="false"] .form-section-chevron {
    transform: rotate(-90deg);
}

.form-section-body {
    padding: var(--space-6);
}

.form-section-body.fs-collapsed {
    display: none;
}

/* ==========================================
   DARK MODE — triggered by .dark on <body>
   ========================================== */
.dark .form-section {
    background: var(--color-dark-800);
    border-color: var(--color-dark-700);
    box-shadow: 0 1px 4px rgba(0,0,0,.35);
}

.dark .form-section:focus-within {
    box-shadow: 0 0 0 3px rgba(99,102,241,.2), 0 1px 4px rgba(0,0,0,.35);
}

.dark .form-section-header,
.dark button.form-section-header {
    background: var(--color-dark-700);
    border-bottom-color: var(--color-dark-600);
}

.dark button.form-section-header:hover {
    background: var(--color-dark-600);
}

.dark .form-section-icon {
    background: rgba(99,102,241,.15);
    color: var(--color-primary-400);
}

.dark .form-section-title {
    color: #f1f5f9;
}

.dark .form-section-desc,
.dark .form-section-chevron {
    color: var(--color-slate-400);
}
</style>
<script>
function toggleFormSection(btn) {
    var expanded = btn.getAttribute('aria-expanded') === 'true';
    btn.setAttribute('aria-expanded', String(!expanded));
    var body = document.getElementById(btn.getAttribute('aria-controls'));
    if (body) { body.classList.toggle('fs-collapsed', expanded); }
}
</script>
HTML;
}
