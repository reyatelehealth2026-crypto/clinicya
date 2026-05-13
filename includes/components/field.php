<?php
/**
 * Field Component
 * Label + input (text/email/number/select/textarea) + help text + error slot
 *
 * @package Clinicya\Components
 * @version 1.0.0
 */

/**
 * Render a labelled form field.
 *
 * @param string $name    Input name attribute
 * @param string $label   Visible label text (Thai OK)
 * @param string $type    'text'|'email'|'number'|'url'|'tel'|'select'|'textarea'
 * @param mixed  $value   Current value (string/int/float)
 * @param array  $options {
 *   'id'          => string   — overrides auto id
 *   'placeholder' => string
 *   'help'        => string   — small hint below input
 *   'error'       => string   — validation error message
 *   'required'    => bool
 *   'disabled'    => bool
 *   'readonly'    => bool
 *   'min'         => string   — for number inputs
 *   'max'         => string   — for number inputs
 *   'step'        => string   — for number inputs
 *   'rows'        => int      — for textarea
 *   'choices'     => array    — for select: ['value' => 'Label', ...]
 *                              or [['value'=>'v','label'=>'L','disabled'=>bool], ...]
 *   'class'       => string   — extra classes on the input element
 *   'wrap_class'  => string   — extra classes on the outer wrapper div
 *   'icon'        => string   — FA class; renders a prefix icon inside the input
 * }
 * @return string HTML
 */
function renderField(string $name, string $label, string $type, $value, array $options = []): string {
    $id          = $options['id']          ?? 'field-' . preg_replace('/[^a-z0-9_-]/i', '-', $name);
    $placeholder = $options['placeholder'] ?? '';
    $help        = $options['help']        ?? '';
    $error       = $options['error']       ?? '';
    $required    = !empty($options['required']);
    $disabled    = !empty($options['disabled']);
    $readonly    = !empty($options['readonly']);
    $extraClass  = $options['class']       ?? '';
    $wrapClass   = $options['wrap_class']  ?? '';
    $icon        = $options['icon']        ?? '';
    $rows        = (int) ($options['rows'] ?? 3);
    $choices     = $options['choices']     ?? [];
    $min         = $options['min']         ?? null;
    $max         = $options['max']         ?? null;
    $step        = $options['step']        ?? null;

    $hasError = $error !== '';
    $errorId  = $id . '-error';
    $helpId   = $id . '-help';

    // Common attributes
    $attrs  = ' id="' . htmlspecialchars($id) . '"';
    $attrs .= ' name="' . htmlspecialchars($name) . '"';
    if ($placeholder !== '') { $attrs .= ' placeholder="' . htmlspecialchars($placeholder) . '"'; }
    if ($required)           { $attrs .= ' required'; }
    if ($disabled)           { $attrs .= ' disabled'; }
    if ($readonly)           { $attrs .= ' readonly'; }
    if ($help !== '')        { $attrs .= ' aria-describedby="' . $helpId . '"'; }
    if ($hasError)           { $attrs .= ' aria-invalid="true" aria-errormessage="' . $errorId . '"'; }

    $baseClass = 'field-input'
               . ($hasError  ? ' field-input-error' : '')
               . ($icon && $type !== 'select' && $type !== 'textarea' ? ' field-input-icon' : '')
               . ($extraClass ? ' ' . $extraClass : '');

    // Build control
    if ($type === 'select') {
        $control = '<select class="' . $baseClass . '"' . $attrs . '>';
        foreach ($choices as $cVal => $cLabel) {
            if (is_array($cLabel)) {
                $cDis     = !empty($cLabel['disabled']) ? ' disabled' : '';
                $cSel     = ((string) $value === (string) $cLabel['value']) ? ' selected' : '';
                $control .= '<option value="' . htmlspecialchars((string) $cLabel['value']) . '"' . $cSel . $cDis . '>'
                          . htmlspecialchars((string) $cLabel['label']) . '</option>';
            } else {
                $cSel     = ((string) $value === (string) $cVal) ? ' selected' : '';
                $control .= '<option value="' . htmlspecialchars((string) $cVal) . '"' . $cSel . '>'
                          . htmlspecialchars((string) $cLabel) . '</option>';
            }
        }
        $control .= '</select>';
    } elseif ($type === 'textarea') {
        $control = '<textarea class="' . $baseClass . '"' . $attrs . ' rows="' . $rows . '">'
                 . htmlspecialchars((string) $value)
                 . '</textarea>';
    } else {
        $numAttrs = '';
        if ($min  !== null) { $numAttrs .= ' min="'  . htmlspecialchars((string) $min)  . '"'; }
        if ($max  !== null) { $numAttrs .= ' max="'  . htmlspecialchars((string) $max)  . '"'; }
        if ($step !== null) { $numAttrs .= ' step="' . htmlspecialchars((string) $step) . '"'; }
        $control = '<input type="' . htmlspecialchars($type) . '" class="' . $baseClass . '"'
                 . $attrs
                 . ' value="' . htmlspecialchars((string) $value) . '"'
                 . $numAttrs . '>';
    }

    // Wrap with icon prefix if needed
    if ($icon && $type !== 'select' && $type !== 'textarea') {
        $controlHtml = '<div class="field-icon-wrap">'
                     . '<i class="' . htmlspecialchars($icon) . ' field-icon-prefix" aria-hidden="true"></i>'
                     . $control
                     . '</div>';
    } else {
        $controlHtml = $control;
    }

    // Assemble
    $html  = '<div class="field-group' . ($wrapClass ? ' ' . $wrapClass : '') . '">';
    $html .= '<label class="field-label" for="' . htmlspecialchars($id) . '">'
           . htmlspecialchars($label)
           . ($required ? '<span class="field-required" aria-hidden="true">*</span>' : '')
           . '</label>';
    $html .= $controlHtml;
    if ($help !== '') {
        $html .= '<p class="field-help" id="' . $helpId . '">' . htmlspecialchars($help) . '</p>';
    }
    if ($hasError) {
        $html .= '<p class="field-error" id="' . $errorId . '" role="alert">'
               . '<i class="fas fa-exclamation-circle" aria-hidden="true"></i> '
               . htmlspecialchars($error) . '</p>';
    }
    $html .= '</div>';

    return $html;
}

/**
 * Inline CSS for the field component.
 * Call once per page.
 *
 * @return string <style> block
 */
function getFieldStyles(): string {
    return <<<CSS
<style>
/* ==========================================
   Field Component — light + dark mode
   Relies on design-tokens.css custom props
   ========================================== */
.field-group {
    margin-bottom: var(--space-5);
}

.field-label {
    display: block;
    font-size: var(--text-sm);
    font-weight: 500;
    color: #374151;
    margin-bottom: var(--space-2);
    line-height: 1.4;
}

.field-required {
    color: var(--color-rose-500);
    margin-left: 3px;
}

.field-input {
    display: block;
    width: 100%;
    padding: 10px var(--space-4);
    font-size: var(--text-sm);
    font-family: var(--font-sans);
    color: #1e293b;
    background: #ffffff;
    border: 1px solid var(--color-slate-300);
    border-radius: var(--radius-md);
    outline: none;
    transition: border-color var(--transition-fast), box-shadow var(--transition-fast);
    appearance: none;
    -webkit-appearance: none;
}

.field-input::placeholder {
    color: var(--color-slate-400);
}

.field-input:focus {
    border-color: var(--color-primary-500);
    box-shadow: 0 0 0 3px rgba(79,70,229,.12);
}

.field-input:disabled {
    background: var(--color-slate-100);
    color: var(--color-dark-500);
    cursor: not-allowed;
}

.field-input[readonly] {
    background: var(--color-slate-50);
}

.field-input-error {
    border-color: var(--color-rose-500);
}

.field-input-error:focus {
    box-shadow: 0 0 0 3px rgba(244,63,94,.15);
}

/* Select — custom arrow */
select.field-input {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%2364748b' d='M6 8L0 0h12z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 14px center;
    padding-right: 36px;
    cursor: pointer;
}

/* Textarea */
textarea.field-input {
    resize: vertical;
    min-height: 80px;
}

/* Icon prefix */
.field-icon-wrap {
    position: relative;
}

.field-icon-prefix {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--color-dark-500);
    font-size: var(--text-sm);
    pointer-events: none;
}

.field-input-icon {
    padding-left: 38px;
}

/* Help + error */
.field-help {
    font-size: var(--text-xs);
    color: var(--color-dark-500);
    margin-top: var(--space-1);
    line-height: 1.5;
}

.field-error {
    font-size: var(--text-xs);
    color: var(--color-rose-600);
    margin-top: var(--space-1);
    display: flex;
    align-items: center;
    gap: 4px;
    line-height: 1.5;
}

/* ==========================================
   DARK MODE
   ========================================== */
.dark .field-label {
    color: var(--color-slate-300);
}

.dark .field-input {
    background: var(--color-dark-700);
    border-color: var(--color-dark-600);
    color: #f1f5f9;
}

.dark .field-input::placeholder {
    color: var(--color-dark-500);
}

.dark .field-input:focus {
    border-color: var(--color-primary-400);
    box-shadow: 0 0 0 3px rgba(99,102,241,.2);
}

.dark .field-input:disabled {
    background: var(--color-dark-800);
    color: var(--color-dark-500);
}

.dark .field-input[readonly] {
    background: var(--color-dark-800);
}

.dark select.field-input {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%2394a3b8' d='M6 8L0 0h12z'/%3E%3C/svg%3E");
    background-color: var(--color-dark-700);
}

.dark select.field-input option {
    background: var(--color-dark-800);
    color: #f1f5f9;
}

.dark .field-icon-prefix {
    color: var(--color-slate-400);
}

.dark .field-help {
    color: var(--color-slate-400);
}

.dark .field-error {
    color: #fda4af;
}
</style>
CSS;
}
