import type { ReactNode } from 'react';

/**
 * PageHeader — React port of includes/components/page-header.php's
 * renderPageHeader($title, $subtitle, $primaryAction, $breadcrumb). Server
 * Component (no interactivity) — a plain title/subtitle/breadcrumb/action
 * shell. Bilingual pages pass a `<span lang="th">…</span> <span
 * lang="en">…</span>` node as `title`/`subtitle` when both languages are
 * needed; plain strings work too (mirrors the PHP component, which only
 * ever received plain escaped text).
 */
export interface PageHeaderAction {
  label: string;
  icon?: ReactNode;
  href?: string;
  variant?: 'primary' | 'success';
}

export interface PageHeaderBreadcrumbItem {
  label: string;
  href?: string | null;
}

export interface PageHeaderProps {
  title: ReactNode;
  subtitle?: ReactNode;
  primaryAction?: PageHeaderAction | null;
  breadcrumb?: PageHeaderBreadcrumbItem[];
}

export function PageHeader({ title, subtitle, primaryAction, breadcrumb = [] }: PageHeaderProps) {
  return (
    <div className="page-header">
      {breadcrumb.length > 0 ? (
        <nav className="page-header-breadcrumb" aria-label="breadcrumb">
          {breadcrumb.map((crumb, i) => {
            const isLast = i === breadcrumb.length - 1;
            return (
              <span key={`${crumb.label}-${i}`}>
                {crumb.href && !isLast ? (
                  <a href={crumb.href} className="page-header-crumb">
                    {crumb.label}
                  </a>
                ) : (
                  <span className="page-header-crumb page-header-crumb-current">{crumb.label}</span>
                )}
                {!isLast ? <span className="page-header-crumb-sep"> / </span> : null}
              </span>
            );
          })}
        </nav>
      ) : null}

      <div className="page-header-row">
        <div className="page-header-text">
          <h1 className="page-header-title">{title}</h1>
          {subtitle ? <p className="page-header-subtitle">{subtitle}</p> : null}
        </div>

        {primaryAction ? (
          <a
            href={primaryAction.href ?? '#'}
            className={`page-header-action page-header-action-${primaryAction.variant ?? 'primary'}`}
          >
            {primaryAction.icon ? <span aria-hidden="true">{primaryAction.icon}</span> : null}
            <span>{primaryAction.label}</span>
          </a>
        ) : null}
      </div>
    </div>
  );
}
