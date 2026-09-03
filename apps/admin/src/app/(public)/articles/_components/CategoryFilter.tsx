import type { ArticleCategoryRow } from '../queries';

/**
 * CategoryFilter — port of articles.php's `.categories-section` chip row
 * (lines 429-443). Only rendered when there's at least one active category
 * (`<?php if (!empty($categories)): ?>`) — the caller (page.tsx) owns that
 * guard, same as PHP. "ทั้งหมด" always links back to the plain `/articles`
 * URL (no `?category=`), each category chip to `/articles?category=<id>`.
 */
export function CategoryFilter({ categories, activeCategoryId }: { categories: ArticleCategoryRow[]; activeCategoryId: number | null }) {
  return (
    <nav aria-label="หมวดหมู่บทความ" className="-mx-4 flex gap-3 overflow-x-auto px-4 py-1">
      <a
        href="/articles"
        className={`shrink-0 whitespace-nowrap rounded-full px-5 py-2 text-sm font-medium transition ${
          activeCategoryId === null ? 'bg-emerald-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-emerald-600 hover:text-white'
        }`}
      >
        ทั้งหมด
      </a>
      {categories.map((cat) => (
        <a
          key={cat.id}
          href={`/articles?category=${cat.id}`}
          className={`shrink-0 whitespace-nowrap rounded-full px-5 py-2 text-sm font-medium transition ${
            activeCategoryId === cat.id ? 'bg-emerald-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-emerald-600 hover:text-white'
          }`}
        >
          {cat.name}
        </a>
      ))}
    </nav>
  );
}
