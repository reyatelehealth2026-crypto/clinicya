'use client';

import type { ReactNode } from 'react';

/**
 * Modal — React port of includes/components/modal.php's renderModal($id,
 * $title, $body, $footer, $options). Client Component: unlike the PHP
 * version (a hidden DOM node toggled by a page-global `openModalShell`/
 * `closeModalShell` pair of functions keyed by DOM id), this is a normal
 * controlled React dialog — `open`/`onClose` props, no global DOM-id
 * registry. Backdrop click and the header close button both call `onClose`.
 */
export type ModalSize = 'sm' | 'md' | 'lg' | 'xl';

export interface ModalProps {
  open: boolean;
  onClose: () => void;
  title: string;
  size?: ModalSize;
  footer?: ReactNode;
  children: ReactNode;
}

export function Modal({ open, onClose, title, size = 'lg', footer, children }: ModalProps) {
  if (!open) {
    return null;
  }

  return (
    <div className="modal-shell" role="dialog" aria-modal="true" aria-label={title}>
      <div className="modal-shell-backdrop" onClick={onClose} />
      <div className={`modal-shell-panel modal-shell-${size}`}>
        <div className="modal-shell-header">
          <h3 className="modal-shell-title">{title}</h3>
          <button type="button" className="modal-shell-close" aria-label="Close" onClick={onClose}>
            ×
          </button>
        </div>

        <div className="modal-shell-body">{children}</div>

        {footer ? <div className="modal-shell-footer">{footer}</div> : null}
      </div>
    </div>
  );
}
