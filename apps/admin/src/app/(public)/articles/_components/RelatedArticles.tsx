import type { RelatedArticleRow } from '../queries';

/**
 * RelatedArticles — port of article.php's `.related-section` grid (lines
 * 626-644). Only rendered when non-empty (caller's guard, matching
 * `<?php if (!empty($relatedArticles)): ?>`). The related-card template
 * (lines 631-640) is deliberately sparser than the main ArticleCard: image
 * only if `featured_image` is set (NO placeholder icon when it's missing —
 * `.related-image` just stays an empty gray box, unlike the main grid's
 * newspaper-icon placeholder) and title only — no excerpt/author/date/
 * category/featured badge.
 */
export function RelatedArticles({ articles }: { articles: RelatedArticleRow[] }) {
  if (articles.length === 0) {
    return null;
  }

  return (
    <section aria-label="บทความที่เกี่ยวข้อง" className="py-10">
      <h3 className="mb-6 text-center text-lg font-semibold text-gray-800">บทความที่เกี่ยวข้อง</h3>
      <div className="grid grid-cols-1 gap-5 sm:grid-cols-3">
        {articles.map((related) => (
          <a
            key={related.id}
            href={`/articles/${encodeURIComponent(related.slug)}`}
            className="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-black/5 transition hover:-translate-y-1 hover:shadow-md"
          >
            <div className="aspect-video bg-gray-100">
              {related.featured_image ? (
                // eslint-disable-next-line @next/next/no-img-element
                <img src={related.featured_image} alt="" className="h-full w-full object-cover" />
              ) : null}
            </div>
            <div className="p-4">
              <h4 className="line-clamp-2 text-sm text-gray-800">{related.title}</h4>
            </div>
          </a>
        ))}
      </div>
    </section>
  );
}
