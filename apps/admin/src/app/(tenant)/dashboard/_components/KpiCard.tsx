/**
 * KpiCard.tsx — LOCAL port of includes/components/kpi-card.php's
 * renderKpiCard($accent, $label, $value, $footer, $icon, $attrs). Server
 * Component (no interactivity).
 *
 * CANDIDATE FOR PROMOTION to apps/admin/src/components/ — kpi-card.php is a
 * shared Archetype-B PHP partial (also used by still-PHP analytics.php per
 * the brief), so this port belongs in the shared component set once a
 * second Next page needs it too. Kept local to dashboard/_components/ for
 * this batch per the allowed-paths brief (must never write directly into
 * src/components/, owned by the users-pages agent) — do not silently
 * fork a second copy elsewhere; promote this one.
 *
 * Deviates from the PHP source in one respect, flagged in the build report:
 * kpi-card.php's `$validAccents` allow-list is only
 * ['indigo','emerald','violet','amber'] — passing 'rose' (which
 * executive.php's unread/problem tiles do whenever their count > 0) falls
 * through to 'indigo', a presentation bug in the PHP component (the visible
 * "alertness" then comes entirely from the separate `kpi-card--alert`
 * modifier class, not the rail accent). This port supports 'rose' properly
 * as a real accent instead of reproducing that fallback — the *data*
 * driving the accent choice (business threshold logic in executiveLogic.ts)
 * is preserved exactly; only the PHP component's own color-validation bug is
 * not replicated.
 */
export type KpiAccent = 'indigo' | 'emerald' | 'violet' | 'amber' | 'rose';

export interface KpiCardProps {
  accent: KpiAccent;
  label: string;
  value: string;
  footer?: string | null;
  /** Mirrors `$attrs = ['class' => 'kpi-card--alert']` — red border/background modifier for over-threshold tiles. */
  alert?: boolean;
  testId?: string;
}

const ACCENT_RAIL_COLOR: Record<KpiAccent, string> = {
  indigo: '#6366f1',
  emerald: '#10b981',
  violet: '#7c3aed',
  amber: '#f59e0b',
  rose: '#f43f5e',
};

export function KpiCard({ accent, label, value, footer, alert = false, testId }: KpiCardProps) {
  return (
    <div
      className={`kpi-card kpi-card--${accent}${alert ? ' kpi-card--alert' : ''}`}
      data-testid={testId}
      data-accent={accent}
      data-alert={alert ? 'true' : 'false'}
      style={{
        position: 'relative',
        display: 'flex',
        border: `1px solid ${alert ? '#fca5a5' : '#e2e8f0'}`,
        borderRadius: 12,
        padding: '16px 18px 16px 22px',
        background: alert ? '#fff5f5' : '#fff',
        gap: 8,
      }}
    >
      <span
        aria-hidden="true"
        style={{ position: 'absolute', top: 0, left: 0, width: 4, height: '100%', background: ACCENT_RAIL_COLOR[accent] }}
      />
      <div style={{ display: 'flex', flexDirection: 'column', gap: 2, minWidth: 0, flex: 1 }}>
        <span style={{ fontSize: 11, fontWeight: 600, color: '#64748b', textTransform: 'uppercase', letterSpacing: '0.08em' }}>
          {label}
        </span>
        <span style={{ fontFamily: 'monospace', fontSize: 28, fontWeight: 600, color: '#0f172a', lineHeight: 1.15 }}>{value}</span>
        {footer ? (
          <span
            style={{
              display: 'inline-flex',
              width: 'fit-content',
              marginTop: 4,
              padding: '3px 10px',
              borderRadius: 999,
              fontSize: 11,
              fontWeight: 600,
              background: '#eef2ff',
              color: '#4338ca',
            }}
          >
            {footer}
          </span>
        ) : null}
      </div>
    </div>
  );
}
