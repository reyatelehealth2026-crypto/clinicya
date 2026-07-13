'use client';

import { useEffect, useState } from 'react';
import { Modal } from '@/components/Modal';
import { createTemplateAction, updateTemplateAction, type TemplateFormInput } from '../actions';
import type { TemplateRow } from '../queries';

/**
 * TemplateFormModal.tsx — client port of templates.php's #templateModal +
 * openModal()/editTemplate() inline script (lines 210-269). One shared
 * Modal (from apps/admin/src/components/Modal.tsx) handles both "เพิ่มเทมเพลต"
 * (create) and "แก้ไขเทมเพลต" (edit), matching the PHP source's single
 * `<form method="POST">` with a hidden `action`/`id` pair whose values get
 * swapped by JS depending on which button opened it.
 */
export interface TemplateModalTarget {
  mode: 'create' | 'edit';
  template?: TemplateRow;
}

const EMPTY_FORM: TemplateFormInput = { name: '', category: '', messageType: 'text', content: '' };

// Note: templates.php's own <style> block defines `.modal-field`/`.modal-input`/
// `.modal-footer-btn*` locally to that one PHP file — those classes have no
// equivalent in apps/admin's shared globals.css (only the Modal component's own
// `.modal-shell-*` classes live there), and adding new global CSS classes is
// outside this batch's allowed paths. Styled with Tailwind utilities instead —
// same visual intent (labeled fields, bordered inputs, primary/neutral footer
// buttons), no new shared class introduced.
const FIELD_LABEL = 'block text-sm font-medium text-slate-800 mb-1.5';
const FIELD_INPUT =
  'w-full px-3 py-2.5 border border-slate-200 rounded-xl text-sm text-slate-800 bg-slate-50 focus:outline-none focus:bg-white focus:border-primary-400 focus:ring-2 focus:ring-primary-400/20 box-border';

export function TemplateFormModal({
  target,
  onClose,
  onSaved,
}: {
  target: TemplateModalTarget | null;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [form, setForm] = useState<TemplateFormInput>(EMPTY_FORM);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!target) return;
    if (target.mode === 'edit' && target.template) {
      setForm({
        name: target.template.name,
        category: target.template.category ?? '',
        messageType: target.template.messageType,
        content: target.template.content,
      });
    } else {
      setForm(EMPTY_FORM);
    }
  }, [target]);

  if (!target) {
    return null;
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSubmitting(true);
    try {
      if (target?.mode === 'edit' && target.template) {
        await updateTemplateAction(target.template.id, form);
      } else {
        await createTemplateAction(form);
      }
      onSaved();
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={target.mode === 'edit' ? 'แก้ไขเทมเพลต' : 'เพิ่มเทมเพลต'}
      size="md"
      footer={
        <>
          <button
            type="button"
            onClick={onClose}
            className="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-xl text-sm font-semibold border border-slate-200 bg-white text-slate-700 hover:bg-slate-50"
          >
            ยกเลิก
          </button>
          <button
            type="submit"
            form="template-form"
            disabled={submitting}
            className="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-xl text-sm font-semibold border border-emerald-500 bg-emerald-500 text-white hover:bg-emerald-600 hover:border-emerald-600 disabled:opacity-60"
          >
            บันทึก
          </button>
        </>
      }
    >
      <form id="template-form" onSubmit={handleSubmit} className="space-y-4">
        <div>
          <label htmlFor="tpl-name" className={FIELD_LABEL}>
            ชื่อเทมเพลต
          </label>
          <input
            id="tpl-name"
            type="text"
            required
            className={FIELD_INPUT}
            placeholder="ชื่อเทมเพลต"
            value={form.name}
            onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
          />
        </div>
        <div>
          <label htmlFor="tpl-category" className={FIELD_LABEL}>
            หมวดหมู่
          </label>
          <input
            id="tpl-category"
            type="text"
            className={FIELD_INPUT}
            placeholder="เช่น ทักทาย, โปรโมชั่น, FAQ"
            value={form.category}
            onChange={(e) => setForm((f) => ({ ...f, category: e.target.value }))}
          />
        </div>
        <div>
          <label htmlFor="tpl-type" className={FIELD_LABEL}>
            ประเภท
          </label>
          <select
            id="tpl-type"
            className={FIELD_INPUT}
            value={form.messageType}
            onChange={(e) => setForm((f) => ({ ...f, messageType: e.target.value }))}
          >
            <option value="text">Text</option>
            <option value="flex">Flex Message (JSON)</option>
          </select>
        </div>
        <div>
          <label htmlFor="tpl-content" className={FIELD_LABEL}>
            เนื้อหา
          </label>
          <textarea
            id="tpl-content"
            rows={6}
            required
            className={FIELD_INPUT}
            value={form.content}
            onChange={(e) => setForm((f) => ({ ...f, content: e.target.value }))}
          />
        </div>
      </form>
    </Modal>
  );
}
