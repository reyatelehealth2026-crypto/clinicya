/**
 * HourlyActivityChart.tsx — port of executive.php's 24-bucket hourly
 * activity chart. The PHP source renders this via a Chart.js line chart
 * loaded from a CDN `<script src="https://cdn.jsdelivr.net/npm/chart.js">`.
 *
 * Simplification flagged in the build report: rather than adding Chart.js
 * (or any charting library) as a new apps/admin dependency for a single
 * static, non-interactive 24-point series, this renders a dependency-free
 * inline SVG bar chart carrying the exact same 24 numbers. No shared chart
 * primitive exists in apps/admin/src/components/ (or anywhere else in the
 * monorepo) yet to reuse instead — this is a plain presentational Server
 * Component, not a candidate for promotion on its own (too dashboard-
 * specific); a real shared chart primitive should be designed once a second
 * page needs one, not improvised here. The parity harness checks the
 * "hourly-activity total" (sum of the 24 buckets), which this preserves
 * exactly — only the pixel rendering differs from the PHP source.
 */
export interface HourlyActivityChartProps {
  /** 24 values, index = hour-of-day (0-23). */
  hourlyActivity: readonly number[];
}

const CHART_HEIGHT = 160;
const BAR_GAP = 2;

export function HourlyActivityChart({ hourlyActivity }: HourlyActivityChartProps) {
  const max = Math.max(1, ...hourlyActivity);
  const barWidth = 100 / hourlyActivity.length;

  return (
    <div style={{ padding: 20 }}>
      <svg
        role="img"
        aria-label="กราฟกิจกรรมรายชั่วโมง"
        viewBox={`0 0 100 ${CHART_HEIGHT}`}
        preserveAspectRatio="none"
        style={{ width: '100%', height: 200, overflow: 'visible' }}
      >
        {hourlyActivity.map((count, hour) => {
          const barHeight = (count / max) * (CHART_HEIGHT - 20);
          return (
            <rect
              key={hour}
              className="hourly-bar"
              x={hour * barWidth + BAR_GAP / 2}
              y={CHART_HEIGHT - barHeight}
              width={Math.max(0, barWidth - BAR_GAP)}
              height={barHeight}
              fill="#6366f1"
              fillOpacity={0.75}
            >
              <title>
                {hour}:00 — {count} ข้อความ
              </title>
            </rect>
          );
        })}
      </svg>
      <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 10, color: '#94a3b8', marginTop: 4 }}>
        <span>0:00</span>
        <span>12:00</span>
        <span>23:00</span>
      </div>
    </div>
  );
}
