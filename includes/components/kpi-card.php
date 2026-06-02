<?php
/**
 * KPI Card Component — Archetype B (Dashboard)
 * แสดงตัวเลข KPI พร้อม accent rail, headline mono, และ footer chip
 *
 * @package REYA\Components
 * @version 1.0.0
 */

/**
 * Render a KPI card.
 *
 * @param string      $accent  Accent colour: indigo | emerald | violet | amber
 * @param string      $label   Small uppercase label (Thai or English)
 * @param string      $value   Headline value (pre-formatted string, e.g. "1,234" or "฿56,789")
 * @param string|null $footer  Optional footer text for the trend chip (e.g. "+19 ใน 7 วัน")
 * @param string      $icon    FontAwesome class (e.g. "fas fa-users"), empty = no icon
 * @param array       $attrs   Extra HTML attributes on root element e.g. ['class' => 'extra']
 * @return string HTML output
 */
function renderKpiCard(string $accent, string $label, string $value, ?string $footer = null, string $icon = '', array $attrs = []): string
{
    $validAccents = ['indigo', 'emerald', 'violet', 'amber'];
    if (!in_array($accent, $validAccents, true)) {
        $accent = 'indigo';
    }

    $extraClass = isset($attrs['class']) ? ' ' . $attrs['class'] : '';
    unset($attrs['class']);

    $attrStr = '';
    foreach ($attrs as $k => $v) {
        $attrStr .= ' ' . htmlspecialchars((string)$k) . '="' . htmlspecialchars((string)$v) . '"';
    }

    $iconHtml = $icon !== ''
        ? '<i class="' . htmlspecialchars($icon) . ' kpi-card__icon-glyph" aria-hidden="true"></i>'
        : '';

    $footerHtml = ($footer !== null && $footer !== '')
        ? '<span class="kpi-card__delta">' . htmlspecialchars($footer) . '</span>'
        : '';

    $labelEsc  = htmlspecialchars($label);
    $valueEsc  = htmlspecialchars($value);

    return <<<HTML
<div class="kpi-card kpi-card--{$accent}{$extraClass}"{$attrStr}>
    <span class="kpi-card__rail" aria-hidden="true"></span>
    <div class="kpi-card__body">
        <div class="kpi-card__label-row">
            {$iconHtml}<span class="kpi-card__label">{$labelEsc}</span>
        </div>
        <div class="kpi-card__value">{$valueEsc}</div>
        {$footerHtml}
    </div>
</div>
HTML;
}

/**
 * Return the <style> block for the KPI card component.
 * Idempotent — emits CSS only once per page.
 *
 * @return string CSS wrapped in <style> tags, or empty string if already emitted.
 */
function getKpiCardStyles(): string
{
    static $emitted = false;
    if ($emitted) {
        return '';
    }
    $emitted = true;

    return <<<'CSS'
<style>
/* ── KPI Card — Archetype B (Dashboard) ─────────────────────────────
   Requires design-tokens.css custom properties.
   ─────────────────────────────────────────────────────────────────── */

/* Base card */
.kpi-card {
    position: relative;
    display: flex;
    align-items: stretch;
    background: #ffffff;
    border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-lg);
    padding: var(--space-5);
    gap: var(--space-4);
    box-shadow: 0 1px 3px rgba(15,23,42,.06), 0 4px 12px rgba(15,23,42,.04);
    transition: box-shadow var(--transition-fast), transform var(--transition-fast),
                border-color var(--transition-fast);
    overflow: hidden;
}

.kpi-card:hover {
    box-shadow: 0 2px 6px rgba(15,23,42,.08), 0 8px 24px rgba(15,23,42,.08);
    transform: translateY(-1px);
    border-color: var(--color-slate-300);
}

/* Accent rail — 4 px strip on the left, intensifies on hover */
.kpi-card__rail {
    position: absolute;
    top: 0;
    left: 0;
    width: 4px;
    height: 100%;
    border-radius: var(--radius-lg) 0 0 var(--radius-lg);
    transition: width var(--transition-fast);
}

.kpi-card:hover .kpi-card__rail {
    width: 5px;
}

/* Rail gradient per accent */
.kpi-card--indigo .kpi-card__rail  { background: linear-gradient(180deg, var(--color-primary-500), var(--color-primary-400)); }
.kpi-card--emerald .kpi-card__rail { background: linear-gradient(180deg, var(--color-emerald-500), var(--color-emerald-400)); }
.kpi-card--violet .kpi-card__rail  { background: linear-gradient(180deg, var(--color-violet-600), var(--color-primary-400)); }
.kpi-card--amber .kpi-card__rail   { background: linear-gradient(180deg, var(--color-amber-500),  var(--color-amber-400)); }

/* Body content */
.kpi-card__body {
    padding-left: var(--space-2);
    display: flex;
    flex-direction: column;
    gap: 2px;
    flex: 1;
    min-width: 0;
}

/* Label row */
.kpi-card__label-row {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    margin-bottom: 2px;
}

.kpi-card__icon-glyph {
    font-size: 12px;
    color: var(--color-dark-500);
    opacity: .65;
    flex-shrink: 0;
}

.kpi-card__label {
    font-size: 11px;
    font-weight: 600;
    color: var(--color-dark-500);
    text-transform: uppercase;
    letter-spacing: 0.08em;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Headline value — JetBrains Mono */
.kpi-card__value {
    font-family: var(--font-mono);
    font-size: 32px;
    font-weight: 600;
    letter-spacing: -0.02em;
    color: var(--color-dark-900);
    line-height: 1.15;
}

/* Footer / delta chip */
.kpi-card__delta {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    margin-top: var(--space-1);
    padding: 3px 10px;
    border-radius: var(--radius-full);
    font-size: 11px;
    font-weight: 600;
    width: fit-content;
}

/* Delta chip tints per accent */
.kpi-card--indigo .kpi-card__delta  { background: var(--color-primary-50);  color: var(--color-primary-700); }
.kpi-card--emerald .kpi-card__delta { background: var(--color-emerald-50);  color: var(--color-emerald-700); }
.kpi-card--violet .kpi-card__delta  { background: var(--color-primary-50);  color: var(--color-violet-600); }
.kpi-card--amber .kpi-card__delta   { background: var(--color-amber-50);    color: var(--color-amber-700); }

/* ── Dark mode ──────────────────────────────────────────── */
.dark .kpi-card {
    background: var(--color-dark-800);
    border-color: var(--color-dark-700);
    box-shadow: 0 1px 3px rgba(0,0,0,.2), 0 4px 12px rgba(0,0,0,.15);
}

.dark .kpi-card:hover {
    border-color: var(--color-dark-600);
    box-shadow: 0 2px 6px rgba(0,0,0,.25), 0 8px 24px rgba(0,0,0,.2);
}

.dark .kpi-card__label      { color: var(--color-slate-400); }
.dark .kpi-card__icon-glyph { color: var(--color-slate-400); }
.dark .kpi-card__value      { color: #f1f5f9; }

.dark .kpi-card--indigo .kpi-card__delta  { background: rgba(99,102,241,.15);  color: var(--color-primary-300); }
.dark .kpi-card--emerald .kpi-card__delta { background: rgba(16,185,129,.15);  color: var(--color-emerald-300); }
.dark .kpi-card--violet .kpi-card__delta  { background: rgba(124,58,237,.15);  color: var(--color-primary-300); }
.dark .kpi-card--amber .kpi-card__delta   { background: rgba(245,158,11,.15);  color: var(--color-amber-300); }
</style>
CSS;
}
