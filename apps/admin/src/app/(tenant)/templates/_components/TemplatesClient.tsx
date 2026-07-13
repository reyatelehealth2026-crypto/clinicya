'use client';

import { useMemo, useState } from 'react';
import { useRouter } from 'next/navigation';
import { EmptyState } from '@/components/EmptyState';
import { TemplateCard } from './TemplateCard';
import { TemplateFormModal, type TemplateModalTarget } from './TemplateFormModal';
import { deleteTemplateAction } from '../actions';
import type { TemplateRow } from '../queries';

/**
 * TemplatesClient.tsx — client island for /templates, combining
 * templates.php's category-filter-bar (lines 158-164,
 * `filterCategory(category, btn)`) and its template grid + Add/Edit/Delete
 * actions (lines 166-208) into one component. The filter bar is "100%
 * client-side" per the brief — ported from raw DOM `style.display` toggling
 * to React state (`activeCategory`), functionally identical (all cards stay
 * mounted; only visibility toggles) but idiomatic React instead of manual
 * DOM writes.
 */
export function TemplatesClient({ templates, categories }: { templates: TemplateRow[]; categories: string[] }) {
  const router = useRouter();
  const [activeCategory, setActiveCategory] = useState('');
  const [modalTarget, setModalTarget] = useState<TemplateModalTarget | null>(null);

  const visibleTemplates = useMemo(
    () => (activeCategory === '' ? templates : templates.filter((t) => t.category === activeCategory)),
    [templates, activeCategory]
  );

  function handleSaved() {
    setModalTarget(null);
    router.refresh();
  }

  async function handleDelete(id: number) {
    await deleteTemplateAction(id);
    router.refresh();
  }

  return (
    <div>
      <div className="flex items-center justify-between gap-3 mb-4 flex-wrap">
        <div>
          <h1 className="text-xl font-bold text-slate-800">Template Library</h1>
          <p className="text-sm text-slate-500 mt-0.5">คลังเทมเพลตข้อความสำหรับส่งหาลูกค้า</p>
        </div>
        <button
          type="button"
          onClick={() => setModalTarget({ mode: 'create' })}
          className="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-xl text-sm font-semibold bg-emerald-500 text-white hover:bg-emerald-600"
        >
          + เพิ่มเทมเพลต
        </button>
      </div>

      <div className="flex flex-wrap gap-2 mb-4">
        <button
          type="button"
          onClick={() => setActiveCategory('')}
          className={`inline-flex items-center px-3.5 py-1.5 rounded-full text-sm font-medium border transition-colors ${
            activeCategory === ''
              ? 'bg-primary-600 border-primary-600 text-white'
              : 'bg-white border-slate-200 text-slate-700 hover:bg-slate-50'
          }`}
        >
          ทั้งหมด
        </button>
        {categories.map((cat) => (
          <button
            key={cat}
            type="button"
            onClick={() => setActiveCategory(cat)}
            className={`inline-flex items-center px-3.5 py-1.5 rounded-full text-sm font-medium border transition-colors ${
              activeCategory === cat
                ? 'bg-primary-600 border-primary-600 text-white'
                : 'bg-white border-slate-200 text-slate-700 hover:bg-slate-50'
            }`}
          >
            {cat}
          </button>
        ))}
      </div>

      {templates.length === 0 ? (
        // No `cta` here: EmptyState's cta is an <a href> (Server Component,
        // no onClick) — "เพิ่มเทมเพลต" needs to open the client-side modal above,
        // which the header button already provides.
        <EmptyState heading="ยังไม่มีเทมเพลต" sub="สร้างเทมเพลตแรกเพื่อเริ่มส่งข้อความ" />
      ) : (
        <div className="grid gap-4" style={{ gridTemplateColumns: 'repeat(auto-fill, minmax(320px, 1fr))' }}>
          {visibleTemplates.map((template) => (
            <TemplateCard
              key={template.id}
              template={template}
              onEdit={() => setModalTarget({ mode: 'edit', template })}
              onDelete={() => handleDelete(template.id)}
            />
          ))}
        </div>
      )}

      <TemplateFormModal target={modalTarget} onClose={() => setModalTarget(null)} onSaved={handleSaved} />
    </div>
  );
}
