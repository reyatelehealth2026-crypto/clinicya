/**
 * MiniChart.tsx — dependency-free inline SVG stand-ins for
 * crm-dashboard-advanced.php's Chart.js canvases (revenueChart,
 * pipelineChart, sourceChart, analyticsRevenueChart, analyticsDealsChart,
 * analyticsCustomerChart, analyticsTicketChart — all loaded from a CDN
 * `<script src="https://cdn.jsdelivr.net/npm/chart.js">` in the PHP source,
 * plus createSparkline()'s hand-rolled <canvas> line).
 *
 * SIMPLIFICATION flagged in the build report — same precedent already
 * established by apps/admin/src/app/(tenant)/dashboard/_components/
 * HourlyActivityChart.tsx and .../analytics/_components/MiniBarChart.tsx:
 * rather than adding Chart.js as a new apps/admin dependency, this renders
 * the exact same numbers as plain SVG. No shared chart primitive exists in
 * apps/admin/src/components/ to reuse instead (charts aren't in that
 * directory's shared-component list this batch's brief names). Only the
 * pixel rendering differs from the PHP source — the underlying series are
 * preserved exactly.
 */

const HEIGHT = 120;

export function Sparkline({ values, color = '#3b82f6' }: { values: readonly number[]; color?: string }) {
  const min = Math.min(...values);
  const max = Math.max(...values);
  const range = max - min || 1;
  const n = Math.max(1, values.length - 1);
  const points = values.map((v, i) => `${(i / n) * 60},${20 - ((v - min) / range) * 20}`).join(' ');

  return (
    <svg width={60} height={20} viewBox="0 0 60 20" role="img" aria-label="sparkline">
      <polyline points={points} fill="none" stroke={color} strokeWidth={2} vectorEffect="non-scaling-stroke" />
    </svg>
  );
}

export function MiniLineChart({ labels, values, color = '#3b82f6', ariaLabel = 'line chart' }: { labels: readonly string[]; values: readonly number[]; color?: string; ariaLabel?: string }) {
  const max = Math.max(1, ...values);
  const n = Math.max(1, values.length - 1);
  const points = values.map((v, i) => `${(i / n) * 100},${HEIGHT - (v / max) * (HEIGHT - 10)}`).join(' ');

  return (
    <div>
      <svg viewBox={`0 0 100 ${HEIGHT}`} preserveAspectRatio="none" style={{ width: '100%', height: 180 }} role="img" aria-label={ariaLabel}>
        <polyline points={points} fill="none" stroke={color} strokeWidth={1.5} vectorEffect="non-scaling-stroke" />
      </svg>
      {labels.length > 0 ? (
        <div className="flex justify-between text-[10px] text-gray-400 mt-1">
          <span>{labels[0]}</span>
          <span>{labels[labels.length - 1]}</span>
        </div>
      ) : null}
    </div>
  );
}

export interface BarDatum {
  label: string;
  value: number;
  color: string;
}

export function MiniBarChart({ data, ariaLabel = 'bar chart' }: { data: readonly BarDatum[]; ariaLabel?: string }) {
  const max = Math.max(1, ...data.map((d) => d.value));
  const barWidth = 100 / Math.max(1, data.length);

  return (
    <div>
      <svg viewBox={`0 0 100 ${HEIGHT}`} preserveAspectRatio="none" style={{ width: '100%', height: 180 }} role="img" aria-label={ariaLabel}>
        {data.map((d, i) => {
          const h = (d.value / max) * (HEIGHT - 10);
          return (
            <rect key={d.label} x={i * barWidth + 1} y={HEIGHT - h} width={Math.max(0, barWidth - 2)} height={h} fill={d.color} fillOpacity={0.85}>
              <title>
                {d.label}: {d.value}
              </title>
            </rect>
          );
        })}
      </svg>
      <div className="flex flex-wrap gap-2 mt-1 text-[10px] text-gray-500">
        {data.map((d) => (
          <span key={d.label} className="inline-flex items-center gap-1">
            <span className="inline-block w-2 h-2 rounded-sm" style={{ backgroundColor: d.color }} />
            {d.label}
          </span>
        ))}
      </div>
    </div>
  );
}

export interface DonutDatum {
  label: string;
  value: number;
  color: string;
}

/** Doughnut stand-in — a horizontal 100%-stacked bar (same proportions a doughnut chart would show), plus a legend. */
export function MiniDonut({ data }: { data: readonly DonutDatum[] }) {
  const total = data.reduce((sum, d) => sum + d.value, 0) || 1;
  return (
    <div>
      <div className="flex w-full h-4 rounded-full overflow-hidden" role="img" aria-label="distribution">
        {data.map((d) => (
          <div key={d.label} style={{ width: `${(d.value / total) * 100}%`, backgroundColor: d.color }} title={`${d.label}: ${d.value}`} />
        ))}
      </div>
      <div className="flex flex-wrap gap-2 mt-2 text-[11px] text-gray-600">
        {data.map((d) => (
          <span key={d.label} className="inline-flex items-center gap-1">
            <span className="inline-block w-2.5 h-2.5 rounded-sm" style={{ backgroundColor: d.color }} />
            {d.label} ({d.value})
          </span>
        ))}
      </div>
    </div>
  );
}

export const STAGE_CHART_COLORS = ['#94a3b8', '#3b82f6', '#8b5cf6', '#f59e0b', '#10b981', '#ef4444'];
