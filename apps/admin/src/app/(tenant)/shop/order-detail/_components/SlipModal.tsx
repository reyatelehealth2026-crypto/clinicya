'use client';

import { useEffect } from 'react';

/**
 * SlipModal.tsx — port of shop/order-detail.php's fullscreen slip-image
 * viewer (`#slipModal`/`openSlipModal()`/`closeSlipModal()`, PHP lines
 * 1216-1245): click-outside-to-close, an explicit close button, and
 * ESC-to-close. Controlled via `src`/`onClose` props (no global DOM-id
 * registry) — same "plain controlled React dialog" pattern as
 * `apps/admin/src/components/Modal.tsx`.
 */
export interface SlipModalProps {
  src: string | null;
  onClose: () => void;
}

export function SlipModal({ src, onClose }: SlipModalProps) {
  useEffect(() => {
    if (!src) return;
    function handleKeyDown(e: KeyboardEvent) {
      if (e.key === 'Escape') onClose();
    }
    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [src, onClose]);

  if (!src) {
    return null;
  }

  return (
    <div
      role="dialog"
      aria-modal="true"
      onClick={(e) => {
        if (e.target === e.currentTarget) onClose();
      }}
      style={{
        position: 'fixed',
        inset: 0,
        background: 'rgba(0,0,0,0.9)',
        zIndex: 2000,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: 'var(--space-4)',
      }}
    >
      <button
        onClick={onClose}
        aria-label="Close"
        style={{
          position: 'absolute',
          top: 'var(--space-4)',
          right: 'var(--space-4)',
          color: '#fff',
          fontSize: 28,
          background: 'transparent',
          border: 'none',
          cursor: 'pointer',
          lineHeight: 1,
        }}
      >
        <i className="fas fa-times" />
      </button>
      <img
        src={src}
        alt="slip"
        style={{ maxWidth: '100%', maxHeight: '100%', objectFit: 'contain', borderRadius: 'var(--radius-lg)', boxShadow: 'var(--shadow-glass-xl)' }}
      />
    </div>
  );
}
