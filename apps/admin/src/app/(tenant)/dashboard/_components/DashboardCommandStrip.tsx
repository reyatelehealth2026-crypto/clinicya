'use client';

/**
 * DashboardCommandStrip.tsx — client-side port of executive.php's date
 * filter input + print button (lines 224-239):
 *   <input type="date" ... onchange="window.location='?tab=executive&date='+this.value">
 *   <button onclick="window.print()">พิมพ์</button>
 * The date-changed nav and window.print() call both need real browser APIs,
 * hence the small 'use client' island — everything else on the executive
 * tab stays a Server Component.
 */
export interface DashboardCommandStripProps {
  dateFilter: string;
  dateDisplay: string;
}

export function DashboardCommandStrip({ dateFilter, dateDisplay }: DashboardCommandStripProps) {
  return (
    <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', justifyContent: 'space-between', gap: 12, marginBottom: 4 }}>
      <p style={{ fontSize: 14, color: '#6b7280', fontWeight: 500, margin: 0 }}>{dateDisplay}</p>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
        <input
          type="date"
          className="dashboard-date-input"
          aria-label="เลือกวันที่ / Select date"
          defaultValue={dateFilter}
          data-testid="dashboard-date-filter"
          onChange={(event) => {
            const value = event.currentTarget.value;
            if (value) {
              window.location.href = `?tab=executive&date=${value}`;
            }
          }}
          style={{ padding: '8px 12px', fontSize: 14, border: '1px solid #e2e8f0', borderRadius: 10, background: '#fff' }}
        />
        <button
          type="button"
          className="dashboard-print-btn"
          onClick={() => window.print()}
          style={{ padding: '8px 12px', fontSize: 14, fontWeight: 500, color: '#4b5563', background: '#fff', border: '1px solid #e2e8f0', borderRadius: 10 }}
        >
          <span lang="th">พิมพ์</span> <span lang="en">/ Print</span>
        </button>
      </div>
    </div>
  );
}
