'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';

const PROTOTYPE_SRC = '/reya/REYA%20Dashboard.html';

export default function ReyaPrototypePage() {
  const [ready, setReady] = useState(false);

  useEffect(() => {
    setReady(true);
  }, []);

  return (
    <main
      style={{
        position: 'fixed',
        inset: 0,
        background: '#0f1f17',
        overflow: 'hidden',
      }}
    >
      <Link
        href="/"
        prefetch={false}
        aria-label="กลับไปยังหน้าหลัก"
        style={{
          position: 'fixed',
          top: 12,
          left: 12,
          zIndex: 50,
          display: 'inline-flex',
          alignItems: 'center',
          gap: 6,
          padding: '8px 12px',
          borderRadius: 999,
          background: 'rgba(255,255,255,0.92)',
          color: '#1f3a2c',
          font: '500 12px/1 -apple-system, BlinkMacSystemFont, "Inter", sans-serif',
          textDecoration: 'none',
          boxShadow: '0 6px 16px rgba(0,0,0,0.18)',
          backdropFilter: 'blur(8px)',
        }}
      >
        <span aria-hidden>←</span>
        <span>กลับ</span>
      </Link>

      {!ready && (
        <div
          style={{
            position: 'absolute',
            inset: 0,
            display: 'grid',
            placeItems: 'center',
            color: 'rgba(255,255,255,0.85)',
            font: '500 14px/1.4 -apple-system, BlinkMacSystemFont, "Inter", sans-serif',
            letterSpacing: 4,
          }}
        >
          REYA · LOADING
        </div>
      )}

      <iframe
        title="REYA Dashboard prototype"
        src={PROTOTYPE_SRC}
        style={{
          position: 'absolute',
          inset: 0,
          width: '100%',
          height: '100%',
          border: 0,
          background: '#2c7656',
          opacity: ready ? 1 : 0,
          transition: 'opacity 240ms ease',
        }}
      />
    </main>
  );
}
