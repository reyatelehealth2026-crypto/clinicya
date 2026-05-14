<?php
/**
 * Modal Component - Standard modal shell
 *
 * Part of the Archetype A (List/CRUD) partial set.
 * A semantic shell with header / body / footer. The opening / closing JS is
 * left to the host page (since each modal often wraps a custom form).
 *
 * Usage:
 *   echo renderModal('productModal', 'เพิ่มสินค้า', $bodyHtml, $footerHtml);
 *   (host uses: openModalShell('productModal') / closeModalShell('productModal'))
 *
 * @package UIRollout
 * @version 1.0.0
 */

/**
 * Render a modal shell.
 *
 * @param string $id     DOM id for the modal root
 * @param string $title  Title shown in the header
 * @param string $body   HTML body content (may contain forms / fields / etc.)
 * @param string|null $footer Optional footer HTML (typically cancel + save buttons)
 * @param array  $options Optional: ['size' => 'sm'|'md'|'lg'|'xl', 'formOpen' => string, 'formClose' => string]
 *                       When formOpen/formClose are provided the body and footer are wrapped in a single <form>.
 * @return string HTML output
 */
function renderModal($id, $title, $body, $footer = null, $options = []) {
    $idEsc = htmlspecialchars((string) $id);
    $titleEsc = htmlspecialchars((string) $title);
    $size = $options['size'] ?? 'lg';
    $sizeClass = 'modal-shell-' . htmlspecialchars($size);

    $formOpen = $options['formOpen'] ?? '';
    $formClose = $options['formClose'] ?? '';
    $useForm = $formOpen !== '' && $formClose !== '';

    $html = '<div id="' . $idEsc . '" class="modal-shell" role="dialog" aria-modal="true" aria-labelledby="' . $idEsc . '_title" hidden>';
    $html .= '<div class="modal-shell-backdrop" data-modal-close="' . $idEsc . '"></div>';
    $html .= '<div class="modal-shell-panel ' . $sizeClass . '">';

    if ($useForm) {
        $html .= $formOpen;
    }

    // Header
    $html .= '<div class="modal-shell-header">';
    $html .= '<h3 id="' . $idEsc . '_title" class="modal-shell-title">' . $titleEsc . '</h3>';
    $html .= '<button type="button" class="modal-shell-close" data-modal-close="' . $idEsc . '" aria-label="Close"><i class="fas fa-times"></i></button>';
    $html .= '</div>';

    // Body
    $html .= '<div class="modal-shell-body">' . $body . '</div>';

    // Footer
    if ($footer !== null && $footer !== '') {
        $html .= '<div class="modal-shell-footer">' . $footer . '</div>';
    }

    if ($useForm) {
        $html .= $formClose;
    }

    $html .= '</div>'; // /panel
    $html .= '</div>'; // /shell
    return $html;
}

/**
 * Modal CSS + small JS hook for [data-modal-close] backdrop / X-button.
 *
 * @return string <style>…</style><script>…</script>
 */
function getModalStyles() {
    return <<<CSS
<style>
/* Modal shell — uses design-tokens.css custom properties. */
.modal-shell {
    position: fixed;
    inset: 0;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--space-4, 16px);
}

.modal-shell[hidden] {
    display: none !important;
}

.modal-shell-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, 0.55);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
}

.modal-shell-panel {
    position: relative;
    background: #ffffff;
    border-radius: var(--radius-lg, 16px);
    box-shadow: 0 24px 64px rgba(15, 23, 42, 0.25), 0 0 0 1px var(--color-slate-200);
    width: 100%;
    max-height: 95vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    animation: modalShellIn 200ms cubic-bezier(0.16, 1, 0.3, 1);
}

.modal-shell-sm  { max-width: 420px; }
.modal-shell-md  { max-width: 640px; }
.modal-shell-lg  { max-width: 960px; }
.modal-shell-xl  { max-width: 1200px; }

@keyframes modalShellIn {
    from { opacity: 0; transform: translateY(8px) scale(0.98); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}

.modal-shell-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: var(--space-4, 16px) var(--space-5, 20px);
    border-bottom: 1px solid var(--color-slate-200);
    background: var(--color-slate-50);
}

.modal-shell-title {
    font-size: var(--text-lg, 18px);
    font-weight: 600;
    color: var(--color-dark-800);
    margin: 0;
}

.modal-shell-close {
    width: 32px;
    height: 32px;
    border-radius: var(--radius-sm, 8px);
    border: none;
    background: transparent;
    color: var(--color-dark-500);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all var(--transition-fast, 150ms ease);
}

.modal-shell-close:hover {
    background: var(--color-slate-200);
    color: var(--color-dark-800);
}

.modal-shell-body {
    padding: var(--space-5, 20px);
    overflow-y: auto;
    flex: 1 1 auto;
}

.modal-shell-footer {
    display: flex;
    justify-content: flex-end;
    gap: var(--space-2, 8px);
    padding: var(--space-4, 16px) var(--space-5, 20px);
    border-top: 1px solid var(--color-slate-200);
    background: var(--color-slate-50);
}

@media (max-width: 640px) {
    .modal-shell {
        padding: var(--space-2, 8px);
    }
    .modal-shell-panel {
        max-height: 96vh;
        border-radius: var(--radius-md, 12px);
    }
}

/* ========================================
   DARK MODE OVERRIDES
   ======================================== */
.dark .modal-shell-backdrop {
    background: rgba(0, 0, 0, 0.65);
}

.dark .modal-shell-panel {
    background: var(--color-dark-800);
    box-shadow: 0 24px 64px rgba(0, 0, 0, 0.5), 0 0 0 1px var(--color-dark-700);
}

.dark .modal-shell-header,
.dark .modal-shell-footer {
    background: var(--color-dark-900);
    border-color: var(--color-dark-700);
}

.dark .modal-shell-title {
    color: var(--color-slate-100);
}

.dark .modal-shell-close {
    color: var(--color-slate-400);
}

.dark .modal-shell-close:hover {
    background: var(--color-dark-700);
    color: var(--color-slate-100);
}
</style>
<script>
/* Modal close helper — attaches once. Hosts can still use their own JS;
   any element with data-modal-close="<id>" hides modal #<id>. */
(function () {
    if (window.__modalShellInit) return;
    window.__modalShellInit = true;
    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-modal-close]');
        if (!el) return;
        var id = el.getAttribute('data-modal-close');
        var modal = document.getElementById(id);
        if (modal) modal.setAttribute('hidden', '');
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('.modal-shell:not([hidden])').forEach(function (m) {
            m.setAttribute('hidden', '');
        });
    });
    window.openModalShell = function (id) {
        var m = document.getElementById(id);
        if (m) m.removeAttribute('hidden');
    };
    window.closeModalShell = function (id) {
        var m = document.getElementById(id);
        if (m) m.setAttribute('hidden', '');
    };
})();
</script>
CSS;
}
