/**
 * Pagination — React port of includes/components/pagination.php's
 * renderPagination($currentPage, $totalPages, $perPage, $baseUrl, $options).
 * Server Component: plain `<a href>` page links, current ± 2 window with
 * first/last + ellipsis, exactly mirroring the PHP component's windowing
 * logic (see pagination.php lines 57-70 for the `$start`/`$end` computation
 * this reproduces).
 */
export interface PaginationProps {
  currentPage: number;
  totalPages: number;
  perPage: number;
  /** Base pathname, e.g. "/users". */
  basePath: string;
  /** Query params to preserve on every page link, minus `page` itself. */
  queryParams?: Record<string, string | undefined>;
  total?: number;
}

function hrefFor(basePath: string, queryParams: Record<string, string | undefined>, page: number): string {
  const query = new URLSearchParams();
  for (const [key, value] of Object.entries(queryParams)) {
    if (value !== undefined && value !== '') {
      query.set(key, value);
    }
  }
  query.set('page', String(page));
  return `${basePath}?${query.toString()}`;
}

export function Pagination({ currentPage, totalPages, perPage, basePath, queryParams = {}, total }: PaginationProps) {
  const page = Math.max(1, currentPage);
  const pages = Math.max(1, totalPages);
  const offset = (page - 1) * perPage;

  const infoNode =
    total !== undefined ? (
      <div className="pagination-info">
        แสดง <b>{(total > 0 ? offset + 1 : 0).toLocaleString()}</b>-<b>{Math.min(offset + perPage, total).toLocaleString()}</b> จาก{' '}
        <b>{total.toLocaleString()}</b> รายการ
      </div>
    ) : (
      <div className="pagination-info">
        หน้า <b>{page.toLocaleString()}</b> / <b>{pages.toLocaleString()}</b>
      </div>
    );

  if (totalPages <= 1) {
    return <div className="pagination-bar">{infoNode}</div>;
  }

  const start = Math.max(1, page - 2);
  const end = Math.min(pages, page + 2);
  const windowPages: number[] = [];
  for (let i = start; i <= end; i++) {
    windowPages.push(i);
  }

  return (
    <div className="pagination-bar">
      {infoNode}
      <div className="pagination-nav">
        {page > 1 ? (
          <a href={hrefFor(basePath, queryParams, page - 1)} className="pagination-link" aria-label="Previous">
            ‹
          </a>
        ) : (
          <span className="pagination-link pagination-link-disabled" aria-disabled="true" aria-label="Previous">
            ‹
          </span>
        )}

        {start > 1 ? (
          <>
            <a href={hrefFor(basePath, queryParams, 1)} className="pagination-link">
              1
            </a>
            {start > 2 ? <span className="pagination-ellipsis">…</span> : null}
          </>
        ) : null}

        {windowPages.map((p) =>
          p === page ? (
            <span key={p} className="pagination-link pagination-link-current" aria-current="page">
              {p}
            </span>
          ) : (
            <a key={p} href={hrefFor(basePath, queryParams, p)} className="pagination-link">
              {p}
            </a>
          )
        )}

        {end < pages ? (
          <>
            {end < pages - 1 ? <span className="pagination-ellipsis">…</span> : null}
            <a href={hrefFor(basePath, queryParams, pages)} className="pagination-link">
              {pages}
            </a>
          </>
        ) : null}

        {page < pages ? (
          <a href={hrefFor(basePath, queryParams, page + 1)} className="pagination-link" aria-label="Next">
            ›
          </a>
        ) : (
          <span className="pagination-link pagination-link-disabled" aria-disabled="true" aria-label="Next">
            ›
          </span>
        )}
      </div>
    </div>
  );
}
