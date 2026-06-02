<?php
/**
 * Toggle Component
 * Switch control with label and optional description — Thai-friendly
 *
 * @package REYA\Components
 * @version 1.0.0
 */

/**
 * Render a toggle (checkbox styled as a switch).
 *
 * @param string      $name        Input name attribute
 * @param string      $label       Primary label text (Thai OK)
 * @param bool        $checked     Whether the toggle is on
 * @param string|null $description Optional secondary description line
 * @param array       $options {
 *   'id'         => string  — overrides auto id
 *   'disabled'   => bool
 *   'value'      => string  — checkbox value (default '1')
 *   'color'      => string  — 'indigo'|'emerald'|'amber'|'rose' (default 'indigo')
 *   'wrap_class' => string  — extra classes on the outer wrapper
 *   'size'       => string  — 'sm'|'md' (default 'md')
 * }
 * @return string HTML
 */
function renderToggle(string $name, string $label, bool $checked, ?string $description = null, array $options = []): string {
    $id        = $options['id']         ?? 'toggle-' . preg_replace('/[^a-z0-9_-]/i', '-', $name);
    $disabled  = !empty($options['disabled']);
    $value     = $options['value']      ?? '1';
    $color     = $options['color']      ?? 'indigo';
    $wrapClass = $options['wrap_class'] ?? '';
    $size      = $options['size']       ?? 'md';

    if (!in_array($color, ['indigo', 'emerald', 'amber', 'rose'], true)) {
        $color = 'indigo';
    }

    $checkedAttr  = $checked  ? ' checked' : '';
    $disabledAttr = $disabled ? ' disabled' : '';

    $descHtml = ($description !== null && $description !== '')
        ? '<span class="toggle-desc">' . htmlspecialchars($description) . '</span>'
        : '';

    return '<div class="toggle-wrap toggle-' . $size . ($wrapClass ? ' ' . $wrapClass : '') . '">'
         . '<label class="toggle-label-row" for="' . htmlspecialchars($id) . '">'
         . '<div class="toggle-text">'
         . '<span class="toggle-label">' . htmlspecialchars($label) . '</span>'
         . $descHtml
         . '</div>'
         . '<div class="toggle-track toggle-' . htmlspecialchars($color) . ($disabled ? ' toggle-disabled' : '') . '">'
         . '<input type="checkbox"'
         . ' id="' . htmlspecialchars($id) . '"'
         . ' name="' . htmlspecialchars($name) . '"'
         . ' value="' . htmlspecialchars($value) . '"'
         . ' class="toggle-input sr-only"'
         . $checkedAttr
         . $disabledAttr . '>'
         . '<span class="toggle-thumb" aria-hidden="true"></span>'
         . '</div>'
         . '</label>'
         . '</div>';
}

/**
 * Inline CSS for the toggle component.
 * Call once per page.
 *
 * @return string <style> block
 */
function getToggleStyles(): string {
    return <<<CSS
<style>
/* ==========================================
   Toggle Component — light + dark mode
   Relies on design-tokens.css custom props
   ========================================== */
.toggle-label-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-4);
    cursor: pointer;
    padding: var(--space-4);
    background: var(--color-slate-50);
    border: 1px solid var(--color-slate-200);
    border-radius: var(--radius-md);
    transition: background var(--transition-fast), border-color var(--transition-fast);
    user-select: none;
    width: 100%;
}

.toggle-label-row:hover {
    background: var(--color-slate-100);
    border-color: var(--color-slate-300);
}

.toggle-text {
    display: flex;
    flex-direction: column;
    gap: 2px;
    flex: 1;
    min-width: 0;
}

.toggle-label {
    font-size: var(--text-sm);
    font-weight: 500;
    color: #1e293b;
    line-height: 1.4;
}

.toggle-desc {
    font-size: var(--text-xs);
    color: var(--color-dark-500);
    line-height: 1.4;
}

/* Track */
.toggle-track {
    position: relative;
    flex-shrink: 0;
}

.toggle-md .toggle-track { width: 44px; height: 24px; }
.toggle-sm .toggle-track { width: 36px; height: 20px; }

.toggle-track::before {
    content: '';
    display: block;
    width: 100%;
    height: 100%;
    border-radius: var(--radius-full);
    background: var(--color-slate-300);
    transition: background var(--transition-base);
}

/* Thumb */
.toggle-thumb {
    position: absolute;
    border-radius: var(--radius-full);
    background: #ffffff;
    box-shadow: 0 1px 3px rgba(0,0,0,.2);
    transition: transform var(--transition-base);
    pointer-events: none;
}

.toggle-md .toggle-thumb { width: 18px; height: 18px; top: 3px; left: 3px; }
.toggle-sm .toggle-thumb { width: 14px; height: 14px; top: 3px; left: 3px; }

/* Checked — translate thumb */
.toggle-md .toggle-track:has(.toggle-input:checked) .toggle-thumb { transform: translateX(20px); }
.toggle-sm .toggle-track:has(.toggle-input:checked) .toggle-thumb { transform: translateX(16px); }

/* Color variants — track fill when checked */
.toggle-indigo:has(.toggle-input:checked)::before   { background: var(--color-primary-500); }
.toggle-emerald:has(.toggle-input:checked)::before  { background: var(--color-emerald-500); }
.toggle-amber:has(.toggle-input:checked)::before    { background: var(--color-amber-500); }
.toggle-rose:has(.toggle-input:checked)::before     { background: var(--color-rose-500); }

/* Disabled */
.toggle-disabled { opacity: .5; }
.toggle-wrap:has(.toggle-input:disabled) .toggle-label-row { cursor: not-allowed; pointer-events: none; }

/* Focus ring for keyboard nav */
.toggle-input:focus-visible ~ .toggle-thumb {
    box-shadow: 0 0 0 3px rgba(79,70,229,.3), 0 1px 3px rgba(0,0,0,.2);
}

/* ==========================================
   DARK MODE
   ========================================== */
.dark .toggle-label-row {
    background: var(--color-dark-700);
    border-color: var(--color-dark-600);
}

.dark .toggle-label-row:hover {
    background: var(--color-dark-600);
    border-color: var(--color-dark-500);
}

.dark .toggle-label { color: #f1f5f9; }

.dark .toggle-desc { color: var(--color-slate-400); }

.dark .toggle-track::before { background: var(--color-dark-500); }

.dark .toggle-thumb {
    background: var(--color-slate-200);
    box-shadow: 0 1px 3px rgba(0,0,0,.4);
}
</style>
CSS;
}
