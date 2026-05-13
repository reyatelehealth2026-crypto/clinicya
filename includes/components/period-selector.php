<?php
/**
 * Period Selector Component — Archetype B (Dashboard)
 * Time-range chips: วันนี้ / 7 วัน / 30 วัน / กำหนดเอง
 *
 * @package Clinicya\Components
 * @version 1.0.0
 */

/**
 * Render a period selector strip.
 *
 * @param string $current     Active option key (e.g. 'today', '7d', '30d', 'custom')
 * @param array  $options     Assoc array of key => label; defaults used when empty
 * @param string $paramName   URL query parameter name to set (default: 'period')
 * @param array  $extraParams Additional query parameters to preserve (key => value)
 * @return string HTML output
 */
function renderPeriodSelector(string $current, array $options = [], string $paramName = 'period', array $extraParams = []): string
{
    if (empty($options)) {
        $options = [
            'today'  => 'วันนี้',
            '7d'     => '7 วัน',
            '30d'    => '30 วัน',
            'custom' => 'กำหนดเอง',
        ];
    }

    $baseUrl = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');

    $chips = '';
    foreach ($options as $key => $label) {
        $isActive  = ($key === $current);
        $activeCls = $isActive ? ' period-chip--active' : '';
        $ariaAttr  = $isActive ? ' aria-current="true"' : '';

        $params   = array_merge($extraParams, [$paramName => $key]);
        $hrefEsc  = htmlspecialchars($baseUrl . '?' . http_build_query($params));
        $labelEsc = htmlspecialchars($label);

        $chips .= '<a href="' . $hrefEsc . '" class="period-chip' . $activeCls . '"' . $ariaAttr . '>' . $labelEsc . '</a>';
    }

    return '<div class="period-selector" role="group" aria-label="เลือกช่วงเวลา">' . $chips . '</div>';
}

/**
 * Return the <style> block for the period selector component.
 * Idempotent — emits CSS only once per page.
 *
 * @return string CSS wrapped in <style> tags, or empty string if already emitted.
 */
function getPeriodSelectorStyles(): string
{
    static $emitted = false;
    if ($emitted) {
        return '';
    }
    $emitted = true;

    return <<<'CSS'
<style>
/* ── Period Selector — Archetype B (Dashboard) ───────────────────────
   Requires design-tokens.css custom properties.
   ─────────────────────────────────────────────────────────────────── */

.period-selector {
    display: inline-flex;
    gap: 4px;
    padding: 4px;
    background: var(--color-slate-100);
    border-radius: var(--radius-md);
    border: 1px solid var(--color-slate-200);
}

.period-chip {
    display: inline-flex;
    align-items: center;
    padding: 7px 16px;
    border-radius: calc(var(--radius-md) - 2px);
    font-size: 13px;
    font-weight: 500;
    color: var(--color-dark-500);
    text-decoration: none;
    transition: background var(--transition-fast), color var(--transition-fast),
                box-shadow var(--transition-fast);
    white-space: nowrap;
    border: 1px solid transparent;
}

.period-chip:hover {
    color: var(--color-dark-900);
    background: rgba(255,255,255,.6);
}

/* Active chip — indigo gradient pill */
.period-chip--active {
    background: linear-gradient(135deg, var(--color-primary-600) 0%, var(--color-primary-500) 100%);
    color: #ffffff;
    border-color: transparent;
    box-shadow: 0 1px 4px rgba(79,70,229,.25), 0 2px 8px rgba(79,70,229,.15);
    font-weight: 600;
    border-radius: calc(var(--radius-md) - 2px);
}

.period-chip--active:hover {
    background: linear-gradient(135deg, var(--color-primary-700) 0%, var(--color-primary-600) 100%);
    color: #ffffff;
}

/* ── Dark mode ─── */
.dark .period-selector {
    background: var(--color-dark-800);
    border-color: var(--color-dark-700);
}

.dark .period-chip {
    color: var(--color-slate-400);
}

.dark .period-chip:hover {
    color: #e2e8f0;
    background: rgba(255,255,255,.05);
}

.dark .period-chip--active {
    background: linear-gradient(135deg, var(--color-primary-600) 0%, var(--color-primary-500) 100%);
    color: #ffffff;
    box-shadow: 0 1px 4px rgba(79,70,229,.35), 0 2px 8px rgba(79,70,229,.2);
}

.dark .period-chip--active:hover {
    background: linear-gradient(135deg, var(--color-primary-500) 0%, var(--color-primary-400) 100%);
    color: #ffffff;
}
</style>
CSS;
}
