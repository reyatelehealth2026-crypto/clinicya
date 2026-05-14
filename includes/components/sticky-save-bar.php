<?php
/**
 * Sticky Save Bar Component
 * Bottom-fixed save/cancel bar — shown only when the form is dirty
 *
 * @package Clinicya\Components
 * @version 1.0.0
 */

/**
 * Render a sticky save/cancel bar.
 *
 * The bar is hidden by default and revealed via JS when any input inside
 * $formId changes (simple dirty-tracking — no library required).
 *
 * @param string      $formId     ID of the <form> element to watch
 * @param string      $saveLabel  Text for the save button (Thai OK)
 * @param string|null $cancelUrl  If set, a cancel link navigates here.
 *                                If null, a reset button discards changes in-page.
 * @param array       $options {
 *   'cancel_label' => string  — override cancel text (default 'ยกเลิก')
 *   'saving_label' => string  — text shown while submitting (default 'กำลังบันทึก...')
 *   'icon'         => string  — FA class for save icon (default 'fas fa-save')
 * }
 * @return string HTML + inline JS
 */
function renderStickySaveBar(string $formId, string $saveLabel, ?string $cancelUrl = null, array $options = []): string {
    $cancelLabel = $options['cancel_label'] ?? 'ยกเลิก';
    $savingLabel = $options['saving_label'] ?? 'กำลังบันทึก...';
    $icon        = $options['icon']         ?? 'fas fa-save';

    $barId = 'ssb-' . preg_replace('/[^a-z0-9_-]/i', '-', $formId);

    if ($cancelUrl !== null) {
        $cancelBtn = '<a href="' . htmlspecialchars($cancelUrl) . '" class="ssb-cancel-btn">'
                   . '<i class="fas fa-times" aria-hidden="true"></i> '
                   . htmlspecialchars($cancelLabel)
                   . '</a>';
    } else {
        $cancelBtn = '<button type="button" class="ssb-cancel-btn"'
                   . ' onclick="ssbReset(' . json_encode($formId) . ',' . json_encode($barId) . ')">'
                   . '<i class="fas fa-times" aria-hidden="true"></i> '
                   . htmlspecialchars($cancelLabel)
                   . '</button>';
    }

    $jsFormId     = json_encode($formId);
    $jsBarId      = json_encode($barId);
    $jsSavingText = json_encode($savingLabel);

    $saveIconHtml = htmlspecialchars($icon);

    return <<<HTML
<div class="ssb-bar" id="{$barId}" hidden>
  <div class="ssb-inner">
    <span class="ssb-status">
      <i class="fas fa-circle-dot ssb-dot" aria-hidden="true"></i>
      มีการเปลี่ยนแปลงที่ยังไม่ได้บันทึก
    </span>
    <div class="ssb-actions">
      {$cancelBtn}
      <button type="submit" form="{$formId}" class="ssb-save-btn" id="{$barId}-save">
        <i class="{$saveIconHtml}" aria-hidden="true"></i>
        <span class="ssb-save-label">{$saveLabel}</span>
      </button>
    </div>
  </div>
</div>
<script>
(function () {
  var formId = {$jsFormId};
  var barId  = {$jsBarId};
  var form   = document.getElementById(formId);
  var bar    = document.getElementById(barId);
  var saveBtn = document.getElementById(barId + '-save');
  if (!form || !bar) return;

  function showBar() { bar.hidden = false; }

  function watchControls() {
    form.querySelectorAll('input, select, textarea').forEach(function (el) {
      if (el._ssbWatched) return;
      el._ssbWatched = true;
      el.addEventListener('change', showBar);
      var t = el.type;
      if (el.tagName === 'INPUT' && (t === 'text' || t === 'number' || t === 'email' || t === 'url' || t === 'tel' || t === 'password')) {
        el.addEventListener('input', showBar);
      }
    });
  }

  watchControls();

  // Re-watch if dynamic rows are added (e.g. addBankRow)
  new MutationObserver(watchControls).observe(form, { childList: true, subtree: true });

  form.addEventListener('submit', function () {
    if (!saveBtn) return;
    var lbl = saveBtn.querySelector('.ssb-save-label');
    if (lbl) lbl.textContent = {$jsSavingText};
    saveBtn.disabled = true;
  });
}());

function ssbReset(formId, barId) {
  var form = document.getElementById(formId);
  var bar  = document.getElementById(barId);
  if (form) form.reset();
  if (bar)  bar.hidden = true;
}
</script>
HTML;
}

/**
 * Inline CSS for the sticky-save-bar component.
 * Call once per page.
 *
 * @return string <style> block
 */
function getStickySaveBarStyles(): string {
    return <<<CSS
<style>
/* ==========================================
   Sticky Save Bar — light + dark mode
   Relies on design-tokens.css custom props
   ========================================== */
.ssb-bar {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    z-index: 200;
    animation: ssbSlideUp var(--transition-base) ease both;
    /* offset for sidebar — matches header.php 260 px sidebar */
    padding-left: 260px;
}

@media (max-width: 768px) {
    .ssb-bar { padding-left: 0; }
}

@keyframes ssbSlideUp {
    from { transform: translateY(100%); opacity: 0; }
    to   { transform: translateY(0);    opacity: 1; }
}

.ssb-inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-4);
    padding: var(--space-3) var(--space-6);
    background: #ffffff;
    border-top: 2px solid var(--color-primary-500);
    box-shadow: 0 -4px 24px rgba(0,0,0,.1);
}

.ssb-status {
    display: flex;
    align-items: center;
    gap: var(--space-2);
    font-size: var(--text-sm);
    font-weight: 500;
    color: #374151;
    flex: 1;
    min-width: 0;
}

.ssb-dot {
    color: var(--color-amber-500);
    font-size: 10px;
    flex-shrink: 0;
    animation: ssbPulse 1.8s ease-in-out infinite;
}

@keyframes ssbPulse {
    0%, 100% { opacity: 1; }
    50%       { opacity: .4; }
}

.ssb-actions {
    display: flex;
    align-items: center;
    gap: var(--space-3);
    flex-shrink: 0;
}

.ssb-cancel-btn {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    padding: 9px var(--space-5);
    font-size: var(--text-sm);
    font-weight: 500;
    color: var(--color-dark-600);
    background: var(--color-slate-100);
    border: 1px solid var(--color-slate-300);
    border-radius: var(--radius-md);
    cursor: pointer;
    text-decoration: none;
    transition: background var(--transition-fast), color var(--transition-fast);
}

.ssb-cancel-btn:hover {
    background: var(--color-slate-200);
    color: #1e293b;
}

.ssb-save-btn {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    padding: 9px var(--space-6);
    font-size: var(--text-sm);
    font-weight: 600;
    color: #ffffff;
    background: var(--color-primary-600);
    border: none;
    border-radius: var(--radius-md);
    cursor: pointer;
    box-shadow: 0 2px 8px rgba(79,70,229,.3);
    transition: background var(--transition-fast), transform var(--transition-fast), box-shadow var(--transition-fast);
}

.ssb-save-btn:hover {
    background: var(--color-primary-700);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(79,70,229,.4);
}

.ssb-save-btn:active { transform: translateY(0); }

.ssb-save-btn:disabled {
    opacity: .7;
    cursor: not-allowed;
    transform: none;
}

@media (max-width: 480px) {
    .ssb-inner {
        flex-direction: column;
        align-items: stretch;
        gap: var(--space-3);
        padding: var(--space-3) var(--space-4);
    }
    .ssb-actions { justify-content: flex-end; }
}

/* ==========================================
   DARK MODE
   ========================================== */
.dark .ssb-inner {
    background: var(--color-dark-800);
    border-top-color: var(--color-primary-500);
    box-shadow: 0 -4px 24px rgba(0,0,0,.4);
}

.dark .ssb-status { color: var(--color-slate-300); }

.dark .ssb-cancel-btn {
    color: var(--color-slate-300);
    background: var(--color-dark-700);
    border-color: var(--color-dark-600);
}

.dark .ssb-cancel-btn:hover {
    background: var(--color-dark-600);
    color: #f1f5f9;
}
</style>
CSS;
}
