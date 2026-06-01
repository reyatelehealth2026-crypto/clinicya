<?php
/**
 * Section Card Component — Archetype B (Dashboard)
 * การ์ดแสดงเนื้อหาพร้อม header stripe, icon, และ action slot
 *
 * @package REYA\Components
 * @version 1.0.0
 */

/**
 * Render a section card.
 *
 * @param string      $title   Section title (Thai or English)
 * @param string      $icon    FontAwesome class (e.g. "fas fa-tags")
 * @param string      $body    Inner HTML body content (caller is responsible for escaping data inside)
 * @param string|null $action  Optional action HTML (ghost button); null = no action slot
 * @param string      $accent  Icon badge accent: indigo | emerald | violet | amber | rose | cyan
 * @param array       $attrs   Extra HTML attributes on root element
 * @return string HTML output
 */
function renderSectionCard(string $title, string $icon, string $body, ?string $action = null, string $accent = 'indigo', array $attrs = []): string
{
    $validAccents = ['indigo', 'emerald', 'violet', 'amber', 'rose', 'cyan'];
    if (!in_array($accent, $validAccents, true)) {
        $accent = 'indigo';
    }

    $extraClass = isset($attrs['class']) ? ' ' . $attrs['class'] : '';
    unset($attrs['class']);

    $attrStr = '';
    foreach ($attrs as $k => $v) {
        $attrStr .= ' ' . htmlspecialchars((string)$k) . '="' . htmlspecialchars((string)$v) . '"';
    }

    $titleEsc  = htmlspecialchars($title);
    $iconEsc   = htmlspecialchars($icon);
    $actionHtml = $action !== null
        ? '<div class="section-card__action">' . $action . '</div>'
        : '';

    return <<<HTML
<div class="section-card{$extraClass}"{$attrStr}>
    <div class="section-card__head section-card__head--{$accent}">
        <div class="section-card__title">
            <span class="section-card__icon-wrap section-card__icon-wrap--{$accent}">
                <i class="{$iconEsc}" aria-hidden="true"></i>
            </span>
            {$titleEsc}
        </div>
        {$actionHtml}
    </div>
    <div class="section-card__body">
        {$body}
    </div>
</div>
HTML;
}

/**
 * Convenience wrapper: flush (no-padding) body variant.
 */
function renderSectionCardFlush(string $title, string $icon, string $body, ?string $action = null, string $accent = 'indigo', array $attrs = []): string
{
    $attrs['class'] = ltrim(($attrs['class'] ?? '') . ' section-card--flush');
    return renderSectionCard($title, $icon, $body, $action, $accent, $attrs);
}

/**
 * Render a ghost action link for the $action slot.
 *
 * @param string $href   Destination URL
 * @param string $label  Button label (default: "ดูทั้งหมด")
 * @return string HTML
 */
function renderSectionActionLink(string $href, string $label = 'ดูทั้งหมด'): string
{
    $hrefEsc  = htmlspecialchars($href);
    $labelEsc = htmlspecialchars($label);
    return <<<HTML
<a href="{$hrefEsc}" class="section-card__ghost-btn">
    {$labelEsc}<i class="fas fa-chevron-right" aria-hidden="true"></i>
</a>
HTML;
}

/**
 * Return the <style> block for the section card component.
 * Idempotent — emits CSS only once per page.
 *
 * @return string CSS wrapped in <style> tags, or empty string if already emitted.
 */
function getSectionCardStyles(): string
{
    static $emitted = false;
    if ($emitted) {
        return '';
    }
    $emitted = true;

    return <<<'CSS'
<style>
/* ── Section Card — Archetype B (Dashboard) ─────────────────────────
   Requires design-tokens.css custom properties.
   ─────────────────────────────────────────────────────────────────── */

.section-card {
    background: #ffffff;
    border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-lg);
    box-shadow: 0 1px 3px rgba(15,23,42,.06), 0 6px 16px rgba(15,23,42,.04);
    overflow: hidden;
}

/* Header with soft gradient stripe */
.section-card__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-3);
    padding: 13px var(--space-5);
    position: relative;
}

/* 1 px gradient stripe at bottom of header */
.section-card__head::after {
    content: '';
    position: absolute;
    left: 0;
    right: 0;
    bottom: 0;
    height: 1px;
}

/* Accent header backgrounds */
.section-card__head--indigo  { background: linear-gradient(135deg, #f8faff 0%, var(--color-primary-50) 100%); }
.section-card__head--emerald { background: linear-gradient(135deg, #f8fffc 0%, var(--color-emerald-50) 100%); }
.section-card__head--violet  { background: linear-gradient(135deg, #faf8ff 0%, var(--color-primary-50) 100%); }
.section-card__head--amber   { background: linear-gradient(135deg, #fffdf5 0%, var(--color-amber-50) 100%); }
.section-card__head--rose    { background: linear-gradient(135deg, #fff8f8 0%, #fff1f2 100%); }
.section-card__head--cyan    { background: linear-gradient(135deg, #f0fdff 0%, #cffafe 100%); }

/* Gradient stripe colours */
.section-card__head--indigo::after  { background: linear-gradient(90deg, var(--color-primary-200), transparent); }
.section-card__head--emerald::after { background: linear-gradient(90deg, var(--color-emerald-200), transparent); }
.section-card__head--violet::after  { background: linear-gradient(90deg, var(--color-primary-200), transparent); }
.section-card__head--amber::after   { background: linear-gradient(90deg, var(--color-amber-200), transparent); }
.section-card__head--rose::after    { background: linear-gradient(90deg, #fecdd3, transparent); }
.section-card__head--cyan::after    { background: linear-gradient(90deg, #a5f3fc, transparent); }

/* Title */
.section-card__title {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    font-size: 14px;
    font-weight: 700;
    color: var(--color-dark-900);
    letter-spacing: -0.01em;
}

/* Icon badge */
.section-card__icon-wrap {
    width: 30px;
    height: 30px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 9px;
    font-size: 13px;
    flex-shrink: 0;
}

.section-card__icon-wrap--indigo  { background: var(--color-primary-100);  color: var(--color-primary-600); }
.section-card__icon-wrap--emerald { background: var(--color-emerald-100);  color: var(--color-emerald-600); }
.section-card__icon-wrap--violet  { background: var(--color-primary-100);  color: var(--color-violet-600); }
.section-card__icon-wrap--amber   { background: var(--color-amber-100);    color: var(--color-amber-600); }
.section-card__icon-wrap--rose    { background: #ffe4e6;                   color: #e11d48; }
.section-card__icon-wrap--cyan    { background: #cffafe;                   color: #0891b2; }

/* Action slot */
.section-card__action { flex-shrink: 0; }

/* Ghost button */
.section-card__ghost-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 13px;
    border-radius: var(--radius-sm);
    font-size: 12px;
    font-weight: 600;
    color: var(--color-primary-600);
    border: 1px solid var(--color-primary-200);
    background: transparent;
    text-decoration: none;
    transition: background var(--transition-fast), border-color var(--transition-fast),
                color var(--transition-fast);
    white-space: nowrap;
}

.section-card__ghost-btn:hover {
    background: var(--color-primary-50);
    border-color: var(--color-primary-300);
    color: var(--color-primary-700);
}

.section-card__ghost-btn i { font-size: 10px; }

/* Body */
.section-card__body { padding: var(--space-5); }
.section-card--flush .section-card__body { padding: 0; }

/* ── List rows inside section cards ──────────────────────── */
.sc-row {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    padding: 12px var(--space-5);
    border-bottom: 1px solid var(--color-slate-100);
    transition: background var(--transition-fast);
}

.sc-row:last-child { border-bottom: none; }
.sc-row:hover      { background: var(--color-slate-50); }

/* ── Empty state ─────────────────────────────────────────── */
.sc-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: var(--space-3);
    padding: 40px var(--space-5);
    text-align: center;
}

.sc-empty__circle {
    width: 96px;
    height: 96px;
    border-radius: var(--radius-full);
    border: 2px dashed var(--color-slate-300);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
    color: var(--color-primary-400);
    flex-shrink: 0;
}

.sc-empty__title {
    font-size: 15px;
    font-weight: 600;
    color: var(--color-dark-900);
    margin: 0;
}

.sc-empty__sub {
    font-size: 13px;
    color: var(--color-dark-500);
    max-width: 260px;
    margin: 0;
    line-height: 1.5;
}

.sc-empty__cta {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    padding: 9px 20px;
    border-radius: var(--radius-md);
    font-size: 13px;
    font-weight: 600;
    background: var(--color-primary-600);
    color: #ffffff;
    text-decoration: none;
    transition: background var(--transition-fast), transform var(--transition-fast);
    margin-top: var(--space-1);
}

.sc-empty__cta:hover {
    background: var(--color-primary-700);
    transform: translateY(-1px);
}

/* ── Dark mode ───────────────────────────────────────────── */
.dark .section-card {
    background: var(--color-dark-800);
    border-color: var(--color-dark-700);
    box-shadow: 0 1px 3px rgba(0,0,0,.2), 0 6px 16px rgba(0,0,0,.15);
}

.dark .section-card__head--indigo  { background: linear-gradient(135deg, var(--color-dark-800), rgba(99,102,241,.07)); }
.dark .section-card__head--emerald { background: linear-gradient(135deg, var(--color-dark-800), rgba(16,185,129,.07)); }
.dark .section-card__head--violet  { background: linear-gradient(135deg, var(--color-dark-800), rgba(124,58,237,.07)); }
.dark .section-card__head--amber   { background: linear-gradient(135deg, var(--color-dark-800), rgba(245,158,11,.07)); }
.dark .section-card__head--rose    { background: linear-gradient(135deg, var(--color-dark-800), rgba(244,63,94,.07)); }
.dark .section-card__head--cyan    { background: linear-gradient(135deg, var(--color-dark-800), rgba(8,145,178,.07)); }

.dark .section-card__head--indigo::after  { background: linear-gradient(90deg, rgba(99,102,241,.3), transparent); }
.dark .section-card__head--emerald::after { background: linear-gradient(90deg, rgba(16,185,129,.3), transparent); }
.dark .section-card__head--violet::after  { background: linear-gradient(90deg, rgba(124,58,237,.3), transparent); }
.dark .section-card__head--amber::after   { background: linear-gradient(90deg, rgba(245,158,11,.3), transparent); }
.dark .section-card__head--rose::after    { background: linear-gradient(90deg, rgba(244,63,94,.3), transparent); }
.dark .section-card__head--cyan::after    { background: linear-gradient(90deg, rgba(8,145,178,.3), transparent); }

.dark .section-card__title { color: #e2e8f0; }

.dark .section-card__icon-wrap--indigo  { background: rgba(99,102,241,.15);  color: var(--color-primary-300); }
.dark .section-card__icon-wrap--emerald { background: rgba(16,185,129,.15);  color: var(--color-emerald-300); }
.dark .section-card__icon-wrap--violet  { background: rgba(124,58,237,.15);  color: var(--color-primary-300); }
.dark .section-card__icon-wrap--amber   { background: rgba(245,158,11,.15);  color: var(--color-amber-300); }
.dark .section-card__icon-wrap--rose    { background: rgba(244,63,94,.15);   color: #fda4af; }
.dark .section-card__icon-wrap--cyan    { background: rgba(8,145,178,.15);   color: #67e8f9; }

.dark .section-card__ghost-btn {
    color: var(--color-primary-400);
    border-color: rgba(99,102,241,.3);
}

.dark .section-card__ghost-btn:hover {
    background: rgba(99,102,241,.1);
    border-color: rgba(99,102,241,.5);
    color: var(--color-primary-300);
}

.dark .sc-row {
    border-bottom-color: var(--color-dark-700);
}

.dark .sc-row:hover { background: var(--color-dark-700); }

.dark .sc-empty__circle {
    border-color: var(--color-dark-600);
    color: var(--color-primary-400);
}

.dark .sc-empty__title { color: #e2e8f0; }
.dark .sc-empty__sub   { color: var(--color-slate-400); }
</style>
CSS;
}
