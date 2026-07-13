'use client';

import { useState } from 'react';
import type { TemplateRow } from '../queries';

/**
 * TemplateCard.tsx — one `.template-card` from templates.php's grid (lines
 * 168-196). `copyTemplate()` in the PHP source builds an inline
 * `onclick="copyTemplate('...')"` string via `addslashes($template['content'])`
 * purely to survive being embedded inside an HTML attribute — that escaping
 * hack has no equivalent here because React just closes over `template.content`
 * directly (no string-templated attribute), so there is nothing to unescape.
 *
 * The PHP page's copy feedback is a shared `fireToast()` (includes/components/
 * toast.php). apps/admin/src/components/** has no shared Toast component yet
 * (out of this batch's boundary per the brief) — substituted with a
 * self-contained 1.5s "คัดลอกแล้ว!" label swap on the copy button itself,
 * same user-visible confirmation, no new shared component invented.
 */
export function TemplateCard({
  template,
  onEdit,
  onDelete,
}: {
  template: TemplateRow;
  onEdit: () => void;
  onDelete: () => void;
}) {
  const [copied, setCopied] = useState(false);

  async function handleCopy() {
    try {
      await navigator.clipboard.writeText(template.content);
      setCopied(true);
      setTimeout(() => setCopied(false), 1500);
    } catch {
      // Clipboard API unavailable/denied — mirrors the PHP page's own lack of
      // a fallback (navigator.clipboard.writeText() with no .catch() there either).
    }
  }

  function handleDelete() {
    if (window.confirm('ลบเทมเพลตนี้?')) {
      onDelete();
    }
  }

  const isText = template.messageType === 'text';

  return (
    <div
      className="bg-white border border-slate-200 rounded-2xl p-4 shadow-sm hover:shadow-md transition-shadow"
      data-category={template.category ?? ''}
    >
      <div className="flex items-start justify-between gap-2 mb-2">
        <div>
          <div className="font-semibold text-sm text-slate-800">{template.name}</div>
          <div className="text-xs text-slate-500 mt-0.5">{template.category || 'ไม่มีหมวดหมู่'}</div>
        </div>
        <span
          className="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium whitespace-nowrap"
          style={isText ? { background: 'var(--color-primary-50)', color: 'var(--color-primary-700)' } : { background: 'rgba(124,58,237,0.08)', color: 'var(--color-violet-600)' }}
        >
          {template.messageType}
        </span>
      </div>
      <div className="p-3 bg-slate-50 rounded-xl mb-3 max-h-[120px] overflow-y-auto">
        <pre className="text-xs whitespace-pre-wrap break-words text-slate-700 m-0 font-sans">{template.content}</pre>
      </div>
      <div className="flex gap-2">
        <button
          type="button"
          onClick={handleCopy}
          className="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 border border-slate-200 rounded-xl text-xs font-medium bg-white text-slate-700 hover:bg-slate-50 hover:border-primary-300 hover:text-primary-600"
        >
          {copied ? 'คัดลอกแล้ว!' : 'คัดลอก'}
        </button>
        <button
          type="button"
          onClick={onEdit}
          className="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 border border-slate-200 rounded-xl text-xs font-medium bg-white text-slate-700 hover:bg-slate-50 hover:border-primary-300 hover:text-primary-600"
        >
          แก้ไข
        </button>
        <button
          type="button"
          onClick={handleDelete}
          aria-label="ลบเทมเพลต"
          className="flex-none w-9 inline-flex items-center justify-center px-2 py-2 border border-slate-200 rounded-xl text-xs font-medium bg-white text-slate-700 hover:bg-rose-50 hover:border-rose-300 hover:text-rose-600"
        >
          ×
        </button>
      </div>
    </div>
  );
}
