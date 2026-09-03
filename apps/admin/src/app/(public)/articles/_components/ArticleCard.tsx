import type { ArticleListRow } from '../queries';
import { formatArticleDate } from '../_lib/format';

/**
 * ArticleCard — port of articles.php's `.article-card` anchor block (lines
 * 450-487): image-or-placeholder + category badge + featured ("แนะนำ")
 * badge + title/excerpt/author/date. Same field-presence guards as the PHP
 * template (`!empty($article[...])`) — a missing image renders the
 * newspaper-icon placeholder, a missing category/author/date simply omits
 * that element rather than rendering an empty one.
 */
export function ArticleCard({ article }: { article: ArticleListRow }) {
  return (
    <a
      href={`/articles/${encodeURIComponent(article.slug)}`}
      className="group flex flex-col overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-black/5 transition hover:-translate-y-1 hover:shadow-lg"
    >
      <div className="relative aspect-video bg-gray-100">
        {article.featured_image ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={article.featured_image} alt={article.title} loading="lazy" className="h-full w-full object-cover" />
        ) : (
          <div className="flex h-full w-full items-center justify-center bg-gradient-to-br from-emerald-50 to-gray-200 text-4xl text-emerald-600/50" aria-hidden="true">
            📰
          </div>
        )}

        {article.category_name ? (
          <span className="absolute bottom-3 left-3 rounded-full bg-black/70 px-3 py-1 text-xs text-white">{article.category_name}</span>
        ) : null}

        {article.is_featured ? (
          <span className="absolute right-3 top-3 rounded-md bg-emerald-600 px-2.5 py-1 text-[11px] font-bold text-white">แนะนำ</span>
        ) : null}
      </div>

      <div className="flex flex-1 flex-col gap-2 p-5">
        <h3 className="line-clamp-2 text-[1.05rem] font-semibold text-gray-800">{article.title}</h3>

        {article.excerpt ? <p className="line-clamp-2 text-sm text-gray-500">{article.excerpt}</p> : null}

        <div className="mt-auto flex items-center gap-4 pt-1 text-[13px] text-gray-400">
          {article.author_name ? (
            <span>
              <span aria-hidden="true">👨‍⚕️ </span>
              {article.author_name}
            </span>
          ) : null}
          {article.published_at ? (
            <span>
              <span aria-hidden="true">🗓️ </span>
              {formatArticleDate(article.published_at)}
            </span>
          ) : null}
        </div>
      </div>
    </a>
  );
}
