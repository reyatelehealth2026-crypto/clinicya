import type { ReactNode } from 'react';

/**
 * Toolbar / SearchBar — React port of includes/components/toolbar.php's
 * renderToolbar($options) merged with users.php's own collapsible
 * "advanced filters" form (users.php lines 1101-1216 — a plain
 * `<form method="GET">` with a search row plus a `<details>`-style
 * collapsible filter grid). Server Component: no client JS is needed for
 * either — a native `<details>` element replaces the PHP page's
 * `toggleAdvancedFilters()` inline `<script>`, and every input just submits
 * the surrounding GET form on demand.
 */
export interface ToolbarProps {
  /** Form action URL — defaults to the current page. */
  action?: string;
  /** Hidden inputs to preserve params not otherwise represented as a field (e.g. tab). */
  hiddenFields?: Record<string, string>;
  search?: { name: string; value: string; placeholder?: string };
  /** Number of active advanced filters — shown as a badge on the toggle. */
  activeFilterCount?: number;
  /** Advanced filter fields, rendered inside a collapsible <details> section. */
  advanced?: ReactNode;
  resetHref?: string;
}

export function Toolbar({ action = '', hiddenFields = {}, search, activeFilterCount = 0, advanced, resetHref }: ToolbarProps) {
  return (
    <div className="toolbar">
      <form className="toolbar-form" method="GET" action={action}>
        {Object.entries(hiddenFields).map(([name, value]) => (
          <input key={name} type="hidden" name={name} value={value} />
        ))}

        {search ? (
          <div className="toolbar-search">
            <input
              type="text"
              name={search.name}
              defaultValue={search.value}
              placeholder={search.placeholder ?? 'ค้นหา…'}
              className="toolbar-search-input"
            />
          </div>
        ) : null}

        <button type="submit" className="toolbar-submit" aria-label="Search">
          ค้นหา
        </button>

        {advanced ? (
          <details className="toolbar-advanced" open={activeFilterCount > 0}>
            <summary className="toolbar-advanced-toggle">
              ตัวกรอง{activeFilterCount > 0 ? <span className="toolbar-filter-badge">{activeFilterCount}</span> : null}
            </summary>
            <div className="toolbar-advanced-body">{advanced}</div>
          </details>
        ) : null}

        {resetHref ? (
          <a href={resetHref} className="toolbar-reset">
            ล้างตัวกรอง
          </a>
        ) : null}
      </form>
    </div>
  );
}
