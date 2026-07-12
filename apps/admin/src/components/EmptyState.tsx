import type { ReactNode } from 'react';

/**
 * EmptyState — React port of includes/components/empty-state.php's
 * renderEmptyState($icon, $heading, $sub, $cta). Server Component.
 */
export interface EmptyStateCta {
  label: string;
  href?: string;
  icon?: ReactNode;
}

export interface EmptyStateProps {
  icon?: ReactNode;
  heading: string;
  sub?: string;
  cta?: EmptyStateCta | null;
}

export function EmptyState({ icon, heading, sub, cta }: EmptyStateProps) {
  return (
    <div className="empty-state">
      {icon ? (
        <div className="empty-state-icon" aria-hidden="true">
          {icon}
        </div>
      ) : null}
      <div className="empty-state-heading">{heading}</div>
      {sub ? <div className="empty-state-sub">{sub}</div> : null}
      {cta ? (
        <a href={cta.href ?? '#'} className="empty-state-cta">
          {cta.icon ? <span aria-hidden="true">{cta.icon}</span> : null}
          <span>{cta.label}</span>
        </a>
      ) : null}
    </div>
  );
}
