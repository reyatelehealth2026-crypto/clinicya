import type { ReactNode } from 'react';

/**
 * SectionCard.tsx — LOCAL port of includes/components/section-card.php's
 * renderSectionCard()/renderSectionCardFlush()/renderSectionActionLink().
 * Server Component (no interactivity).
 *
 * CANDIDATE FOR PROMOTION to apps/admin/src/components/ — same reasoning as
 * KpiCard.tsx: section-card.php is a shared Archetype-B PHP partial also
 * used by still-PHP analytics.php. Kept local per the allowed-paths brief.
 */
export type SectionAccent = 'indigo' | 'emerald' | 'violet' | 'amber' | 'rose' | 'cyan';

export interface SectionCardProps {
  title: string;
  accent?: SectionAccent;
  /** Ghost-button action slot, e.g. <SectionActionLink href="user-tags.php" label="จัดการ" />. */
  action?: ReactNode;
  /** Optional badge rendered next to the title (mirrors executive.php's problem-count `db-section-badge`). */
  badge?: ReactNode;
  /** No-padding body variant — renderSectionCardFlush(). */
  flush?: boolean;
  /** section-card--alert modifier — red border, used when the section's content is in an alert state. */
  alert?: boolean;
  children: ReactNode;
  testId?: string;
}

const ACCENT_BG: Record<SectionAccent, string> = {
  indigo: '#eef2ff',
  emerald: '#ecfdf5',
  violet: '#f5f3ff',
  amber: '#fffbeb',
  rose: '#fff1f2',
  cyan: '#ecfeff',
};

const ACCENT_FG: Record<SectionAccent, string> = {
  indigo: '#4338ca',
  emerald: '#047857',
  violet: '#6d28d9',
  amber: '#b45309',
  rose: '#e11d48',
  cyan: '#0e7490',
};

export function SectionCard({ title, accent = 'indigo', action, badge, flush = false, alert = false, children, testId }: SectionCardProps) {
  return (
    <div
      className={`section-card${flush ? ' section-card--flush' : ''}${alert ? ' section-card--alert' : ''}`}
      data-testid={testId}
      style={{ background: '#fff', border: `1px solid ${alert ? '#fca5a5' : '#e2e8f0'}`, borderRadius: 12, overflow: 'hidden' }}
    >
      <div
        className={`section-card__head section-card__head--${accent}`}
        style={{
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
          gap: 12,
          padding: '13px 20px',
          background: alert ? '#fef2f2' : ACCENT_BG[accent],
        }}
      >
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, fontSize: 14, fontWeight: 700, color: ACCENT_FG[accent] }}>
          <span>{title}</span>
          {badge}
        </div>
        {action ? <div className="section-card__action">{action}</div> : null}
      </div>
      <div className="section-card__body" style={{ padding: flush ? 0 : 20 }}>
        {children}
      </div>
    </div>
  );
}

export interface SectionActionLinkProps {
  href: string;
  label?: string;
}

/** Port of renderSectionActionLink($href, $label = 'ดูทั้งหมด'). */
export function SectionActionLink({ href, label = 'ดูทั้งหมด' }: SectionActionLinkProps) {
  return (
    <a
      href={href}
      className="section-card__ghost-btn"
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: 5,
        padding: '5px 13px',
        borderRadius: 6,
        fontSize: 12,
        fontWeight: 600,
        color: '#4f46e5',
        border: '1px solid #c7d2fe',
        textDecoration: 'none',
        whiteSpace: 'nowrap',
      }}
    >
      {label}
    </a>
  );
}

export interface EmptyStateProps {
  title: string;
  subtitle?: string;
  ctaHref?: string;
  ctaLabel?: string;
  testId?: string;
}

/** Port of the `sc-empty` markup repeated throughout executive.php/crm.php. */
export function EmptyState({ title, subtitle, ctaHref, ctaLabel, testId }: EmptyStateProps) {
  return (
    <div
      className="sc-empty"
      data-testid={testId}
      style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 10, padding: '36px 16px', textAlign: 'center' }}
    >
      <p style={{ fontSize: 14, fontWeight: 600, color: '#0f172a', margin: 0 }}>{title}</p>
      {subtitle ? <p style={{ fontSize: 12, color: '#64748b', maxWidth: 260, margin: 0 }}>{subtitle}</p> : null}
      {ctaHref && ctaLabel ? (
        <a
          href={ctaHref}
          style={{
            display: 'inline-flex',
            padding: '8px 18px',
            borderRadius: 8,
            fontSize: 12,
            fontWeight: 600,
            background: '#4f46e5',
            color: '#fff',
            textDecoration: 'none',
          }}
        >
          {ctaLabel}
        </a>
      ) : null}
    </div>
  );
}
