/**
 * MiniBarChart.tsx / MiniLineChart.tsx — dependency-free inline SVG stand-ins
 * for overview.php's / dashboard.php's Chart.js canvases (messagesChart,
 * followersChart, revenueChart, usersChart, messageTypesChart, hourlyChart —
 * all loaded from a CDN `<script src="https://cdn.jsdelivr.net/npm/chart.js">`
 * in the PHP source).
 *
 * SIMPLIFICATION flagged in the build report, same rationale + precedent as
 * apps/admin/src/app/(tenant)/dashboard/_components/HourlyActivityChart.tsx:
 * rather than adding Chart.js as a new apps/admin dependency for a handful of
 * small, largely non-interactive time series, this renders the exact same
 * numbers as plain SVG bars/lines. No shared chart primitive exists yet in
 * apps/admin/src/components/ to reuse instead. Only the pixel rendering
 * differs from the PHP source — the underlying series (dates, values) are
 * preserved exactly, which is what parity checks against.
 */

const HEIGHT = 140;

export interface BarSeries {
  label: string;
  color: string;
  values: readonly number[];
}

export function MiniStackedBarChart({ labels, series }: { labels: readonly string[]; series: readonly BarSeries[] }) {
  const max = Math.max(1, ...series.flatMap((s) => s.values));
  const n = Math.max(1, labels.length);
  const barWidth = 100 / n;

  return (
    <div>
      <svg viewBox={`0 0 100 ${HEIGHT}`} preserveAspectRatio="none" style={{ width: '100%', height: 180 }} role="img" aria-label="กราฟแท่งรายวัน">
        {labels.map((label, i) => {
          let stackY = HEIGHT;
          return (
            <g key={label ?? i}>
              {series.map((s) => {
                const v = s.values[i] ?? 0;
                const h = (v / max) * (HEIGHT - 10);
                stackY -= h;
                return (
                  <rect
                    key={s.label}
                    x={i * barWidth + 1}
                    y={stackY}
                    width={Math.max(0, barWidth - 2)}
                    height={h}
                    fill={s.color}
                    fillOpacity={0.85}
                  >
                    <title>
                      {label} — {s.label}: {v}
                    </title>
                  </rect>
                );
              })}
            </g>
          );
        })}
      </svg>
      <div className="flex gap-3 mt-1 text-xs text-slate-500">
        {series.map((s) => (
          <span key={s.label} className="inline-flex items-center gap-1">
            <span className="inline-block w-2.5 h-2.5 rounded-sm" style={{ backgroundColor: s.color }} />
            {s.label}
          </span>
        ))}
      </div>
    </div>
  );
}

export function MiniLineChart({ values, color = '#10b981', ariaLabel = 'กราฟเส้น' }: { values: readonly number[]; color?: string; ariaLabel?: string }) {
  const max = Math.max(1, ...values);
  const n = Math.max(1, values.length - 1);
  const points = values
    .map((v, i) => {
      const x = (i / n) * 100;
      const y = HEIGHT - (v / max) * (HEIGHT - 10);
      return `${x},${y}`;
    })
    .join(' ');

  return (
    <svg viewBox={`0 0 100 ${HEIGHT}`} preserveAspectRatio="none" style={{ width: '100%', height: 180 }} role="img" aria-label={ariaLabel}>
      <polyline points={points} fill="none" stroke={color} strokeWidth={1.5} vectorEffect="non-scaling-stroke" />
    </svg>
  );
}
