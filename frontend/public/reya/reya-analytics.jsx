/* REYA Dashboard — Analytics page (rich charts edition) */

const { useState: useStateAna, useEffect: useEffectAna, useRef: useRefAna, useMemo: useMemoAna } = React;

// ─── Deterministic pseudo-random (so reload is stable) ──────────────────
function seedRand(seed) {
  let s = seed;
  return () => {
    s = (s * 9301 + 49297) % 233280;
    return s / 233280;
  };
}

// =========================================================================
// HERO REVENUE CHART — multi-series area + animated draw-in + tooltip
// =========================================================================
function RevenueChart() {
  const W = 720, H = 280;
  const PAD = { l: 44, r: 16, t: 28, b: 32 };
  const innerW = W - PAD.l - PAD.r;
  const innerH = H - PAD.t - PAD.b;

  // 30 data points — current month + prev month + AI
  const data = useMemoAna(() => {
    const r = seedRand(42);
    return Array.from({ length: 30 }, (_, i) => {
      const base = 28 + i * 1.05;
      const noise = (r() - 0.5) * 14;
      const trend = Math.sin(i * 0.45) * 9;
      const current = Math.max(8, base + noise + trend);
      const prev = Math.max(8, base * 0.85 + (r() - 0.5) * 12 + Math.sin(i * 0.42) * 7);
      const ai = Math.max(2, 6 + i * 0.18 + (r() - 0.5) * 3.5);
      return { d: i + 1, current, prev, ai };
    });
  }, []);

  const yMax = 80;
  const x = (i) => PAD.l + (i / (data.length - 1)) * innerW;
  const y = (v) => PAD.t + innerH - (v / yMax) * innerH;

  // Build paths
  const linePath = (key) =>
    data.map((d, i) => `${i === 0 ? 'M' : 'L'} ${x(i).toFixed(1)},${y(d[key]).toFixed(1)}`).join(' ');
  const areaPath = (key) =>
    `${linePath(key)} L ${x(data.length - 1)},${PAD.t + innerH} L ${x(0)},${PAD.t + innerH} Z`;

  // Hover state
  const [hoverIdx, setHoverIdx] = useStateAna(null);
  const svgRef = useRefAna(null);

  const onMove = (e) => {
    const r = svgRef.current.getBoundingClientRect();
    const px = ((e.clientX - r.left) / r.width) * W;
    if (px < PAD.l - 10 || px > W - PAD.r + 10) {
      setHoverIdx(null);
      return;
    }
    const i = Math.round(((px - PAD.l) / innerW) * (data.length - 1));
    setHoverIdx(Math.max(0, Math.min(data.length - 1, i)));
  };

  // Path length for animated draw-in
  const pathRef = useRefAna(null);
  useEffectAna(() => {
    if (!pathRef.current) return;
    const len = pathRef.current.getTotalLength();
    pathRef.current.style.transition = 'none';
    pathRef.current.style.strokeDasharray = len;
    pathRef.current.style.strokeDashoffset = len;
    // Force reflow then animate
    void pathRef.current.getBoundingClientRect();
    pathRef.current.style.transition = 'stroke-dashoffset 1400ms cubic-bezier(0.33, 1, 0.68, 1)';
    pathRef.current.style.strokeDashoffset = '0';
  }, []);

  const totalCurrent = data.reduce((a, b) => a + b.current, 0);
  const totalPrev = data.reduce((a, b) => a + b.prev, 0);
  const totalAi = data.reduce((a, b) => a + b.ai, 0);

  const hover = hoverIdx != null ? data[hoverIdx] : null;

  return (
    <div style={{ position: 'relative' }}>
      <svg
        ref={svgRef}
        viewBox={`0 0 ${W} ${H}`}
        style={{ width: '100%', height: 'auto', maxHeight: 320, cursor: 'crosshair', display: 'block' }}
        onMouseMove={onMove}
        onMouseLeave={() => setHoverIdx(null)}
      >
        <defs>
          <linearGradient id="rc-current" x1="0" x2="0" y1="0" y2="1">
            <stop offset="0%" stopColor="#2c7656" stopOpacity="0.28" />
            <stop offset="100%" stopColor="#2c7656" stopOpacity="0" />
          </linearGradient>
          <linearGradient id="rc-prev" x1="0" x2="0" y1="0" y2="1">
            <stop offset="0%" stopColor="#aed6c2" stopOpacity="0.22" />
            <stop offset="100%" stopColor="#aed6c2" stopOpacity="0" />
          </linearGradient>
          <linearGradient id="rc-ai" x1="0" x2="0" y1="0" y2="1">
            <stop offset="0%" stopColor="#f59e0b" stopOpacity="0.18" />
            <stop offset="100%" stopColor="#f59e0b" stopOpacity="0" />
          </linearGradient>
          <filter id="rc-glow" x="-50%" y="-50%" width="200%" height="200%">
            <feGaussianBlur stdDeviation="3" result="blur" />
            <feMerge>
              <feMergeNode in="blur" />
              <feMergeNode in="SourceGraphic" />
            </feMerge>
          </filter>
        </defs>

        {/* Grid + y-axis */}
        {[0, 20, 40, 60, 80].map((v) => (
          <g key={v}>
            <line
              x1={PAD.l}
              x2={W - PAD.r}
              y1={y(v)}
              y2={y(v)}
              stroke="#e2e8f0"
              strokeWidth="1"
              strokeDasharray="2 4"
            />
            <text
              x={PAD.l - 8}
              y={y(v) + 4}
              fontSize="10"
              fill="#94a3b8"
              textAnchor="end"
              fontFamily="Geist Mono, monospace"
            >
              ฿{v}k
            </text>
          </g>
        ))}

        {/* Previous month — dashed line + soft area */}
        <path d={areaPath('prev')} fill="url(#rc-prev)" />
        <path
          d={linePath('prev')}
          fill="none"
          stroke="#aed6c2"
          strokeWidth="1.8"
          strokeDasharray="5 4"
        />

        {/* AI consults — secondary */}
        <path d={areaPath('ai')} fill="url(#rc-ai)" />
        <path d={linePath('ai')} fill="none" stroke="#f59e0b" strokeWidth="1.8" />

        {/* Current month — hero */}
        <path d={areaPath('current')} fill="url(#rc-current)" />
        <path
          ref={pathRef}
          d={linePath('current')}
          fill="none"
          stroke="#2c7656"
          strokeWidth="2.5"
          strokeLinecap="round"
          strokeLinejoin="round"
          filter="url(#rc-glow)"
        />

        {/* Dots on line — every 5 */}
        {data.map((d, i) =>
          i % 5 === 0 || i === data.length - 1 ? (
            <circle
              key={i}
              cx={x(i)}
              cy={y(d.current)}
              r="3.5"
              fill="#fff"
              stroke="#2c7656"
              strokeWidth="2"
              style={{
                opacity: 0,
                animation: `chart-in 600ms ${300 + i * 30}ms ease-out forwards`,
              }}
            />
          ) : null
        )}

        {/* X-axis labels */}
        {[1, 5, 10, 15, 20, 25, 30].map((d) => (
          <text
            key={d}
            x={x(d - 1)}
            y={H - 10}
            fontSize="10"
            fill="#94a3b8"
            textAnchor="middle"
            fontFamily="LINE Seed Sans TH, sans-serif"
          >
            {d} พ.ค.
          </text>
        ))}

        {/* Hover crosshair */}
        {hover && (
          <g>
            <line
              x1={x(hoverIdx)}
              x2={x(hoverIdx)}
              y1={PAD.t}
              y2={PAD.t + innerH}
              stroke="#2c7656"
              strokeWidth="1"
              strokeDasharray="3 3"
              opacity="0.45"
            />
            <circle cx={x(hoverIdx)} cy={y(hover.current)} r="6" fill="#2c7656" />
            <circle cx={x(hoverIdx)} cy={y(hover.current)} r="3" fill="#fff" />
          </g>
        )}

        {/* Latest value callout */}
        <g>
          <rect
            x={x(data.length - 1) - 48}
            y={y(data[data.length - 1].current) - 32}
            width="60"
            height="22"
            rx="6"
            fill="#0f172a"
          />
          <text
            x={x(data.length - 1) - 18}
            y={y(data[data.length - 1].current) - 17}
            fontSize="10"
            fontWeight="700"
            fill="#fff"
            textAnchor="middle"
            fontFamily="Geist Mono, monospace"
          >
            ฿{Math.round(data[data.length - 1].current)}k
          </text>
        </g>
      </svg>

      {/* Tooltip */}
      {hover && (
        <div
          style={{
            position: 'absolute',
            left: `${((x(hoverIdx) - 80) / W) * 100}%`,
            top: 12,
            background: '#0f172a',
            color: '#fff',
            padding: '8px 12px',
            borderRadius: 10,
            fontSize: 11,
            minWidth: 160,
            boxShadow: '0 8px 24px rgba(15,23,42,0.25)',
            pointerEvents: 'none',
            transition: 'left 80ms ease-out',
          }}
        >
          <div style={{ fontWeight: 700, marginBottom: 4 }}>{hover.d} พ.ค. 2569</div>
          <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12 }}>
            <span style={{ opacity: 0.75 }}>เดือนนี้</span>
            <b style={{ color: '#7eb89c', fontVariantNumeric: 'tabular-nums' }}>
              ฿{Math.round(hover.current)}k
            </b>
          </div>
          <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, marginTop: 2 }}>
            <span style={{ opacity: 0.75 }}>เดือนก่อน</span>
            <b style={{ opacity: 0.8, fontVariantNumeric: 'tabular-nums' }}>
              ฿{Math.round(hover.prev)}k
            </b>
          </div>
          <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, marginTop: 2 }}>
            <span style={{ opacity: 0.75 }}>AI consult</span>
            <b style={{ color: '#fbbf24', fontVariantNumeric: 'tabular-nums' }}>
              ฿{Math.round(hover.ai * 1.2)}k
            </b>
          </div>
        </div>
      )}

      {/* Footer stats */}
      <div
        style={{
          display: 'grid',
          gridTemplateColumns: 'repeat(3, 1fr)',
          gap: 12,
          marginTop: 16,
          paddingTop: 16,
          borderTop: '1px solid var(--divider)',
        }}
      >
        {[
          ['เดือนนี้', '฿' + Math.round(totalCurrent) + 'k', '+14.8%', '#2c7656', true],
          ['เดือนก่อน', '฿' + Math.round(totalPrev) + 'k', '−2.4%', '#aed6c2', false],
          ['AI consult', '฿' + Math.round(totalAi * 1.2) + 'k', '+24.6%', '#f59e0b', true],
        ].map(([nm, v, ch, c, up], i) => (
          <div key={i} style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
            <span style={{ width: 10, height: 10, borderRadius: '50%', background: c, flexShrink: 0 }} />
            <div style={{ flex: 1 }}>
              <div style={{ fontSize: 10, color: 'var(--fg-3)', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.06em' }}>{nm}</div>
              <div style={{ fontSize: 16, fontWeight: 800, fontVariantNumeric: 'tabular-nums' }}>{v}</div>
            </div>
            <div style={{ fontSize: 11, fontWeight: 700, color: up ? 'var(--success)' : 'var(--danger)' }}>
              {ch}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}

// =========================================================================
// DONUT CHART — path-based arcs (more reliable than stroke-dasharray)
// =========================================================================
function DonutChart({ data, total, totalLabel = 'รวม', centerSuffix = '' }) {
  const SIZE = 200;
  const cx = SIZE / 2, cy = SIZE / 2;
  const R_OUTER = 90;
  const R_INNER = 64;
  const sum = data.reduce((a, b) => a + b.value, 0);

  // Helper to build a donut segment as a path
  function arcPath(a1, a2, rOuter, rInner) {
    const x1o = cx + rOuter * Math.cos(a1);
    const y1o = cy + rOuter * Math.sin(a1);
    const x2o = cx + rOuter * Math.cos(a2);
    const y2o = cy + rOuter * Math.sin(a2);
    const x1i = cx + rInner * Math.cos(a2);
    const y1i = cy + rInner * Math.sin(a2);
    const x2i = cx + rInner * Math.cos(a1);
    const y2i = cy + rInner * Math.sin(a1);
    const large = (a2 - a1) > Math.PI ? 1 : 0;
    return `M ${x1o},${y1o} A ${rOuter},${rOuter} 0 ${large} 1 ${x2o},${y2o} L ${x1i},${y1i} A ${rInner},${rInner} 0 ${large} 0 ${x2i},${y2i} Z`;
  }

  // Build segments (start at -π/2 = top)
  let angle = -Math.PI / 2;
  const segments = data.map((d, i) => {
    const frac = d.value / sum;
    const a1 = angle;
    const a2 = angle + frac * 2 * Math.PI;
    angle = a2;
    return { ...d, a1, a2, frac, i };
  });

  const [hover, setHover] = useStateAna(null);
  const active = hover != null ? segments[hover] : null;

  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
      <div style={{ position: 'relative', width: SIZE, height: SIZE, flexShrink: 0 }}>
        <svg viewBox={`0 0 ${SIZE} ${SIZE}`} style={{ width: '100%', height: '100%', display: 'block' }}>
          {segments.map((s, i) => {
            const isActive = hover === i;
            const rO = isActive ? R_OUTER + 4 : R_OUTER;
            const rI = isActive ? R_INNER - 2 : R_INNER;
            return (
              <path
                key={i}
                d={arcPath(s.a1, s.a2, rO, rI)}
                fill={s.color}
                style={{
                  cursor: 'pointer',
                  opacity: hover != null && !isActive ? 0.4 : 1,
                  transition: 'opacity 180ms, d 180ms',
                  filter: isActive ? 'drop-shadow(0 4px 8px rgba(44,118,86,0.25))' : 'none',
                  transformOrigin: `${cx}px ${cy}px`,
                  animation: `donut-grow 700ms ${i * 80}ms cubic-bezier(0.33, 1, 0.68, 1) backwards`,
                }}
                onMouseEnter={() => setHover(i)}
                onMouseLeave={() => setHover(null)}
              />
            );
          })}
        </svg>
        {/* Center label */}
        <div
          style={{
            position: 'absolute',
            inset: 0,
            display: 'grid',
            placeItems: 'center',
            textAlign: 'center',
            pointerEvents: 'none',
          }}
        >
          <div>
            <div style={{ fontSize: 10, color: 'var(--fg-3)', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.08em' }}>
              {active ? active.label : totalLabel}
            </div>
            <div style={{
              fontSize: 24, fontWeight: 800, letterSpacing: '-0.02em', marginTop: 2,
              fontVariantNumeric: 'tabular-nums',
              color: active ? active.color : 'var(--fg-1)',
              transition: 'color 200ms',
              lineHeight: 1.1,
            }}>
              {active ? (active.frac * 100).toFixed(0) + '%' : (total + centerSuffix)}
            </div>
            <div style={{ fontSize: 10, color: 'var(--fg-3)', marginTop: 2 }}>
              {active ? `${active.value.toLocaleString()} ${active.unit || ''}`.trim() : `${data.length} หมวด`}
            </div>
          </div>
        </div>
      </div>
      <div style={{ flex: 1, display: 'flex', flexDirection: 'column', gap: 6 }}>
        {segments.map((s, i) => (
          <div
            key={i}
            onMouseEnter={() => setHover(i)}
            onMouseLeave={() => setHover(null)}
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: 8,
              padding: '6px 10px',
              borderRadius: 8,
              cursor: 'pointer',
              background: hover === i ? 'var(--slate-50)' : 'transparent',
              transition: 'background 150ms',
              borderLeft: `3px solid ${hover === i ? s.color : 'transparent'}`,
              animation: `act-in 380ms ${i * 60}ms backwards`,
            }}
          >
            <span style={{ width: 12, height: 12, borderRadius: 3, background: s.color, flexShrink: 0 }} />
            <span style={{ fontSize: 12, fontWeight: 600, flex: 1 }}>{s.label}</span>
            <span style={{ fontSize: 12, fontWeight: 700, fontVariantNumeric: 'tabular-nums' }}>
              {(s.frac * 100).toFixed(0)}%
            </span>
          </div>
        ))}
      </div>
    </div>
  );
}

// =========================================================================
// DAILY BAR CHART — 30 bars with hover
// =========================================================================
function DailyBars() {
  const data = useMemoAna(() => {
    const r = seedRand(7);
    return Array.from({ length: 30 }, (_, i) => {
      const base = 28 + i * 0.7;
      const v = Math.max(12, base + (r() - 0.5) * 22 + Math.sin(i * 0.5) * 8);
      const isWeekend = (i % 7 === 5 || i % 7 === 6);
      return { d: i + 1, v: Math.round(v), weekend: isWeekend };
    });
  }, []);

  const max = Math.max(...data.map(d => d.v));
  const [hover, setHover] = useStateAna(null);

  const total = data.reduce((a, b) => a + b.v, 0);
  const avg = Math.round(total / data.length);
  const peak = data.reduce((m, d) => d.v > m.v ? d : m);

  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'flex-end', gap: 3, height: 180, paddingBottom: 24, position: 'relative' }}>
        {data.map((d, i) => {
          const h = (d.v / max) * 100;
          const isHover = hover === i;
          const isPeak = peak.d === d.d;
          return (
            <div
              key={i}
              style={{ flex: 1, height: '100%', display: 'flex', flexDirection: 'column', justifyContent: 'flex-end', position: 'relative', cursor: 'pointer' }}
              onMouseEnter={() => setHover(i)}
              onMouseLeave={() => setHover(null)}
            >
              <div
                style={{
                  width: '100%',
                  height: `${h}%`,
                  background: isHover
                    ? 'linear-gradient(180deg, #1c4d39, #235e45)'
                    : isPeak
                    ? 'linear-gradient(180deg, #f59e0b, #b45309)'
                    : d.weekend
                    ? 'linear-gradient(180deg, var(--brand-300), var(--brand-500))'
                    : 'linear-gradient(180deg, var(--brand-400), var(--brand-600))',
                  borderRadius: '4px 4px 0 0',
                  transition: 'background 150ms, transform 150ms',
                  transformOrigin: 'bottom',
                  transform: isHover ? 'scaleY(1.02) translateY(-1px)' : 'scaleY(1)',
                  boxShadow: isHover ? '0 4px 12px rgba(44,118,86,0.3)' : 'none',
                  animation: `bar-grow 700ms ${i * 18}ms cubic-bezier(0.33, 1, 0.68, 1) backwards`,
                }}
              />
              {(isHover || isPeak) && (
                <div
                  style={{
                    position: 'absolute',
                    bottom: `calc(${h}% + 6px)`,
                    left: '50%',
                    transform: 'translateX(-50%)',
                    background: isPeak && !isHover ? '#b45309' : '#0f172a',
                    color: '#fff',
                    fontSize: 10,
                    fontWeight: 700,
                    padding: '3px 7px',
                    borderRadius: 5,
                    whiteSpace: 'nowrap',
                    pointerEvents: 'none',
                    zIndex: 2,
                    fontVariantNumeric: 'tabular-nums',
                  }}
                >
                  ฿{d.v}k
                  {isPeak && !isHover && ' · 🔥'}
                </div>
              )}
              <span
                style={{
                  position: 'absolute',
                  bottom: -20,
                  left: '50%',
                  transform: 'translateX(-50%)',
                  fontSize: 9,
                  color: isHover || isPeak ? 'var(--fg-1)' : 'var(--fg-3)',
                  fontWeight: isHover || isPeak ? 700 : 500,
                  fontFamily: 'var(--font-mono)',
                }}
              >
                {d.d}
              </span>
            </div>
          );
        })}
      </div>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginTop: 16, paddingTop: 14, borderTop: '1px solid var(--divider)' }}>
        <div style={{ display: 'flex', gap: 24 }}>
          <div>
            <div style={{ fontSize: 10, color: 'var(--fg-3)', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.06em' }}>รวม 30 วัน</div>
            <div style={{ fontSize: 18, fontWeight: 800, fontVariantNumeric: 'tabular-nums' }}>฿{total.toLocaleString()}k</div>
          </div>
          <div>
            <div style={{ fontSize: 10, color: 'var(--fg-3)', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.06em' }}>เฉลี่ย/วัน</div>
            <div style={{ fontSize: 18, fontWeight: 800, fontVariantNumeric: 'tabular-nums' }}>฿{avg.toLocaleString()}k</div>
          </div>
          <div>
            <div style={{ fontSize: 10, color: 'var(--fg-3)', fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.06em' }}>วันสูงสุด 🔥</div>
            <div style={{ fontSize: 18, fontWeight: 800, fontVariantNumeric: 'tabular-nums', color: 'var(--warning-fg)' }}>฿{peak.v}k · {peak.d} พ.ค.</div>
          </div>
        </div>
        <div style={{ display: 'flex', gap: 12, fontSize: 11, color: 'var(--fg-2)', fontWeight: 500 }}>
          <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}>
            <span style={{ width: 8, height: 8, borderRadius: 2, background: 'linear-gradient(180deg, var(--brand-400), var(--brand-600))' }} />
            วันธรรมดา
          </span>
          <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}>
            <span style={{ width: 8, height: 8, borderRadius: 2, background: 'linear-gradient(180deg, var(--brand-300), var(--brand-500))' }} />
            วันหยุด
          </span>
        </div>
      </div>
    </div>
  );
}

// =========================================================================
// HEATMAP — 7 days × 16 hours, color intensity
// =========================================================================
function Heatmap() {
  const days = ['จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.', 'อา.'];
  const hours = Array.from({ length: 16 }, (_, i) => i + 8); // 8 → 23
  const data = useMemoAna(() => {
    const r = seedRand(99);
    return days.map((_, d) =>
      hours.map((h) => {
        // Peak: weekdays 10-14, evening 17-20
        const isPeak = (d < 5 && h >= 10 && h <= 14) || (h >= 17 && h <= 20);
        const isMid = (h >= 9 && h <= 16) || (h >= 17 && h <= 21);
        const base = isPeak ? 0.75 : isMid ? 0.4 : 0.12;
        return Math.min(1, Math.max(0.05, base + (r() - 0.5) * 0.25));
      })
    );
  }, []);

  const [hover, setHover] = useStateAna(null);

  const colorFor = (v) => {
    // 0 → very light, 1 → deep brand
    const lerp = (a, b, t) => a + (b - a) * t;
    const r = Math.round(lerp(238, 28, v));
    const g = Math.round(lerp(245, 92, v));
    const b = Math.round(lerp(241, 69, v));
    return `rgb(${r},${g},${b})`;
  };

  return (
    <div>
      <div style={{ display: 'grid', gridTemplateColumns: 'auto 1fr', gap: 6, marginTop: 4 }}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 3, paddingTop: 20 }}>
          {days.map((d, i) => (
            <div
              key={i}
              style={{
                height: 22,
                display: 'grid',
                placeItems: 'center',
                fontSize: 10,
                fontWeight: 700,
                color: hover && hover[0] === i ? 'var(--brand-700)' : 'var(--fg-3)',
                transition: 'color 150ms',
              }}
            >
              {d}
            </div>
          ))}
        </div>
        <div>
          <div
            style={{
              display: 'grid',
              gridTemplateColumns: `repeat(${hours.length}, 1fr)`,
              gap: 3,
              marginBottom: 4,
            }}
          >
            {hours.map((h, i) => (
              <div
                key={h}
                style={{
                  fontSize: 9,
                  fontFamily: 'var(--font-mono)',
                  textAlign: 'center',
                  color: hover && hover[1] === i ? 'var(--brand-700)' : 'var(--fg-3)',
                  fontWeight: hover && hover[1] === i ? 700 : 500,
                  transition: 'color 150ms',
                }}
              >
                {h % 2 === 0 ? h : ''}
              </div>
            ))}
          </div>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 3 }}>
            {data.map((row, d) => (
              <div
                key={d}
                style={{ display: 'grid', gridTemplateColumns: `repeat(${hours.length}, 1fr)`, gap: 3 }}
              >
                {row.map((v, h) => {
                  const isHover = hover && hover[0] === d && hover[1] === h;
                  return (
                    <div
                      key={h}
                      style={{
                        height: 22,
                        background: colorFor(v),
                        borderRadius: 4,
                        cursor: 'pointer',
                        transition: 'transform 120ms, box-shadow 120ms',
                        transform: isHover ? 'scale(1.18)' : 'scale(1)',
                        boxShadow: isHover ? '0 4px 10px rgba(44,118,86,0.3)' : 'none',
                        position: 'relative',
                        zIndex: isHover ? 5 : 1,
                        animation: `kpi-in 480ms ${(d * hours.length + h) * 5}ms backwards`,
                      }}
                      onMouseEnter={() => setHover([d, h, v])}
                      onMouseLeave={() => setHover(null)}
                      title={`${days[d]} ${hours[h]}:00 — ${Math.round(v * 100)}%`}
                    />
                  );
                })}
              </div>
            ))}
          </div>
        </div>
      </div>
      <div
        style={{
          marginTop: 14,
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
        }}
      >
        <div style={{ fontSize: 11, color: 'var(--fg-2)' }}>
          {hover ? (
            <span>
              <b>{days[hover[0]]} {hours[hover[1]]}:00</b> — กิจกรรม {Math.round(hover[2] * 100)}%
            </span>
          ) : (
            <span>
              Peak: <b style={{ color: 'var(--brand-600)' }}>วันธรรมดา 12:00–14:00</b> และ <b style={{ color: 'var(--brand-600)' }}>เย็น 18:00–20:00</b>
            </span>
          )}
        </div>
        <div style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 10, color: 'var(--fg-3)' }}>
          <span>น้อย</span>
          {[0.1, 0.3, 0.5, 0.7, 0.9].map((v) => (
            <span key={v} style={{ width: 14, height: 14, background: colorFor(v), borderRadius: 3 }} />
          ))}
          <span>มาก</span>
        </div>
      </div>
    </div>
  );
}

// =========================================================================
// NPS RADIAL GAUGE
// =========================================================================
function NpsGauge({ score = 4.8, max = 5, total = 247, nps = 68 }) {
  // Half-circle gauge
  const W = 240, H = 140;
  const R = 95;
  const cx = W / 2, cy = H - 16;
  const startA = Math.PI, endA = 0; // half circle, left → right
  const frac = score / max;
  const curA = startA + (endA - startA) * frac;

  const arc = (a1, a2) => {
    const x1 = cx + R * Math.cos(a1);
    const y1 = cy + R * Math.sin(a1) * -1;
    const x2 = cx + R * Math.cos(a2);
    const y2 = cy + R * Math.sin(a2) * -1;
    const large = Math.abs(a2 - a1) > Math.PI ? 1 : 0;
    return `M ${x1} ${y1} A ${R} ${R} 0 ${large} 1 ${x2} ${y2}`;
  };
  const pathRef = useRefAna(null);

  useEffectAna(() => {
    if (!pathRef.current) return;
    const len = pathRef.current.getTotalLength();
    pathRef.current.style.transition = 'none';
    pathRef.current.style.strokeDasharray = len;
    pathRef.current.style.strokeDashoffset = len;
    void pathRef.current.getBoundingClientRect();
    pathRef.current.style.transition = 'stroke-dashoffset 1200ms cubic-bezier(0.33, 1, 0.68, 1)';
    pathRef.current.style.strokeDashoffset = '0';
  }, []);

  return (
    <div style={{ textAlign: 'center', padding: '8px 0 12px' }}>
      <svg viewBox={`0 0 ${W} ${H}`} style={{ width: '100%', maxWidth: 240, display: 'block', margin: '0 auto' }}>
        <defs>
          <linearGradient id="gauge-grad" x1="0" x2="1" y1="0" y2="0">
            <stop offset="0%" stopColor="#dc2626" />
            <stop offset="50%" stopColor="#f59e0b" />
            <stop offset="100%" stopColor="#16a34a" />
          </linearGradient>
        </defs>
        {/* Track */}
        <path d={arc(startA, endA)} fill="none" stroke="#f1f5f9" strokeWidth="14" strokeLinecap="round" />
        {/* Value */}
        <path
          ref={pathRef}
          d={arc(startA, curA)}
          fill="none"
          stroke="url(#gauge-grad)"
          strokeWidth="14"
          strokeLinecap="round"
        />
        {/* Tick marks */}
        {[1, 2, 3, 4, 5].map((s) => {
          const a = startA + (endA - startA) * ((s - 1) / 4);
          const x1 = cx + (R - 16) * Math.cos(a);
          const y1 = cy - (R - 16) * Math.sin(a);
          const x2 = cx + (R + 4) * Math.cos(a);
          const y2 = cy - (R + 4) * Math.sin(a);
          return (
            <g key={s}>
              <line x1={x1} y1={y1} x2={x2} y2={y2} stroke="#cbd5e1" strokeWidth="1" />
              <text
                x={cx + (R + 16) * Math.cos(a)}
                y={cy - (R + 16) * Math.sin(a) + 4}
                fontSize="9"
                fill="#94a3b8"
                textAnchor="middle"
                fontFamily="Geist Mono, monospace"
              >
                {s}
              </text>
            </g>
          );
        })}
        {/* Center label */}
        <text x={cx} y={cy - 30} fontSize="38" fontWeight="800" fill="#0f172a" textAnchor="middle" fontFamily="LINE Seed Sans TH, sans-serif">
          {score}
        </text>
        <text x={cx} y={cy - 10} fontSize="11" fill="#94a3b8" textAnchor="middle" fontFamily="LINE Seed Sans TH, sans-serif" fontWeight="600">
          จาก {max} ดาว
        </text>
      </svg>
      <div style={{ marginTop: 4, fontSize: 11, color: 'var(--fg-3)' }}>
        จาก <b style={{ color: 'var(--fg-1)' }}>{total}</b> รีวิว · NPS <b style={{ color: 'var(--success)' }}>+{nps}</b>
      </div>
      <div style={{ marginTop: 12, display: 'flex', flexDirection: 'column', gap: 4 }}>
        {[5, 4, 3, 2, 1].map((s) => {
          const pct = [78, 16, 4, 1, 1][5 - s];
          return (
            <div key={s} style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 11 }}>
              <span style={{ width: 14, color: 'var(--fg-3)', textAlign: 'right' }}>{s}★</span>
              <div style={{ flex: 1, height: 6, background: 'var(--slate-100)', borderRadius: 9999, overflow: 'hidden' }}>
                <div
                  style={{
                    height: '100%',
                    width: `${pct}%`,
                    background: s >= 4 ? 'var(--success)' : s === 3 ? 'var(--warning)' : 'var(--danger)',
                    borderRadius: 9999,
                    animation: `bar-grow 800ms ${(5 - s) * 100}ms cubic-bezier(0.33,1,0.68,1) backwards`,
                    transformOrigin: 'left',
                  }}
                />
              </div>
              <span style={{ width: 30, textAlign: 'right', color: 'var(--fg-3)', fontVariantNumeric: 'tabular-nums', fontWeight: 600 }}>
                {pct}%
              </span>
            </div>
          );
        })}
      </div>
    </div>
  );
}

// =========================================================================
// AnalyticsPage
// =========================================================================
function AnalyticsPage() {
  const categoryData = [
    { label: 'ยาทั่วไป', value: 482, color: '#2c7656', unit: 'ออเดอร์' },
    { label: 'วิตามิน', value: 318, color: '#4d9876', unit: 'ออเดอร์' },
    { label: 'ความงาม', value: 184, color: '#7eb89c', unit: 'ออเดอร์' },
    { label: 'แม่และเด็ก', value: 98, color: '#aed6c2', unit: 'ออเดอร์' },
    { label: 'อุปกรณ์', value: 60, color: '#d6ebe0', unit: 'ออเดอร์' },
  ];
  const sourceData = [
    { label: 'LINE Mini App', value: 62, color: '#2c7656', unit: '%' },
    { label: 'Walk-in', value: 22, color: '#0ea5e9', unit: '%' },
    { label: 'Telepharmacy', value: 11, color: '#f59e0b', unit: '%' },
    { label: 'Other', value: 5, color: '#94a3b8', unit: '%' },
  ];

  return (
    <div className="page" data-screen-label="Analytics">
      <div className="page-head">
        <div className="titles">
          <h1>Analytics</h1>
          <div className="sub"><Lic name="trending-up" size={12} /> 30 วันล่าสุด · Powered by Odoo ERP</div>
        </div>
        <div className="actions">
          <div className="seg">
            <button>7 วัน</button>
            <button className="active">30 วัน</button>
            <button>90 วัน</button>
            <button>1 ปี</button>
          </div>
          <button className="btn ghost"><Lic name="calendar" size={13} /> ช่วงเอง</button>
          <button className="btn brand"><Lic name="download" size={13} /> Export</button>
        </div>
      </div>

      <div className="page-body">
        <div className="kpis">
          <div className="kpi">
            <div className="top"><div className="ic"><Lic name="dollar-sign" size={13} /></div> รายได้รวม</div>
            <div className="val">฿1.24M</div>
            <div className="delta up"><Lic name="trending-up" size={11} /> 14.8% YoY</div>
            <div className="spark"><Spark points={[820,860,900,920,1050,1180,1240]} /></div>
          </div>
          <div className="kpi">
            <div className="top"><div className="ic info"><Lic name="shopping-cart" size={13} /></div> ออเดอร์รวม</div>
            <div className="val">1,142</div>
            <div className="delta up"><Lic name="trending-up" size={11} /> 12.1%</div>
            <div className="spark"><Spark points={[800,840,900,960,1020,1080,1142]} color="var(--info)" /></div>
          </div>
          <div className="kpi">
            <div className="top"><div className="ic warn"><Lic name="users-round" size={13} /></div> ลูกค้า active</div>
            <div className="val">847</div>
            <div className="delta up"><Lic name="trending-up" size={11} /> 8.2%</div>
            <div className="spark"><Spark points={[680,710,740,770,800,820,847]} color="var(--warning)" /></div>
          </div>
          <div className="kpi">
            <div className="top"><div className="ic success"><Lic name="sparkles" size={13} /></div> AI savings</div>
            <div className="val">฿38.2k</div>
            <div className="delta up"><Lic name="trending-up" size={11} /> 24.6%</div>
            <div className="spark"><Spark points={[18,22,26,28,32,35,38]} color="var(--success)" /></div>
          </div>
        </div>

        {/* Hero revenue chart */}
        <div className="panel">
          <div className="panel-head">
            <div>
              <h3><Lic name="trending-up" size={14} /> รายได้ 30 วัน</h3>
              <div className="sub">เปรียบเทียบเดือนนี้ · เดือนก่อน · AI consult — เลื่อนเมาส์ดูรายวัน</div>
            </div>
            <div className="tools"><button className="more">รายละเอียด ›</button></div>
          </div>
          <div className="panel-body">
            <RevenueChart />
          </div>
        </div>

        <div className="grid-2">
          {/* Daily bars */}
          <div className="panel" style={{marginTop:0}}>
            <div className="panel-head">
              <div>
                <h3><Lic name="bar-chart-2" size={14} /> ยอดขายรายวัน</h3>
                <div className="sub">30 วันล่าสุด · hover ดูรายวัน</div>
              </div>
              <div className="tools"><button className="more">รายชั่วโมง ›</button></div>
            </div>
            <div className="panel-body"><DailyBars /></div>
          </div>

          {/* Category donut */}
          <div className="panel" style={{marginTop:0}}>
            <div className="panel-head">
              <div>
                <h3><Lic name="pill" size={14} /> หมวดสินค้าขายดี</h3>
                <div className="sub">1,142 ออเดอร์รวม</div>
              </div>
            </div>
            <div className="panel-body">
              <DonutChart data={categoryData} total="1,142" totalLabel="ออเดอร์รวม" />
            </div>
          </div>
        </div>

        {/* Heatmap full-width */}
        <div className="panel">
          <div className="panel-head">
            <div>
              <h3><Lic name="activity" size={14} /> Activity heatmap</h3>
              <div className="sub">เวลาที่ลูกค้าใช้แอป — ดูได้รายวัน × รายชั่วโมง</div>
            </div>
            <div className="tools">
              <span style={{fontSize:11,color:'var(--fg-3)'}}>ค่าเฉลี่ย 4 สัปดาห์</span>
            </div>
          </div>
          <div className="panel-body">
            <Heatmap />
          </div>
        </div>

        <div className="grid-3">
          {/* Customer source donut */}
          <div className="panel" style={{marginTop:0}}>
            <div className="panel-head">
              <h3><Lic name="users-round" size={14} /> ที่มาของลูกค้า</h3>
            </div>
            <div className="panel-body" style={{padding: '20px 18px'}}>
              <DonutChart data={sourceData} total="2,847" totalLabel="ลูกค้ารวม" />
            </div>
          </div>

          {/* NPS gauge */}
          <div className="panel" style={{marginTop:0}}>
            <div className="panel-head">
              <h3><Lic name="star" size={14} /> ความพึงพอใจ</h3>
            </div>
            <div className="panel-body">
              <NpsGauge />
            </div>
          </div>

          {/* AI funnel */}
          <div className="panel" style={{marginTop:0}}>
            <div className="panel-head">
              <h3><Lic name="sparkles" size={14} /> AI Co-Pilot Funnel</h3>
            </div>
            <div className="panel-body" style={{padding: '12px 18px 18px'}}>
              {[
                ['ข้อความที่เข้ามา', 2184, 100, 'var(--brand-500)'],
                ['AI ทำคำแนะนำได้', 2058, 94, 'var(--brand-500)'],
                ['เภสัชกรยอมรับ', 1791, 82, 'var(--success)'],
                ['ปิดเคสด้วย AI', 1567, 72, 'var(--success)'],
              ].map(([nm, n, pct, c], i) => (
                <div key={i} style={{marginTop: i === 0 ? 0 : 14, position: 'relative'}}>
                  <div style={{display:'flex',justifyContent:'space-between',marginBottom:6,fontSize:12}}>
                    <span style={{fontWeight:600}}>{nm}</span>
                    <span style={{fontVariantNumeric:'tabular-nums'}}>
                      <b>{n.toLocaleString()}</b>
                      <span style={{color:'var(--fg-3)',marginLeft:6,fontSize:11}}>{pct}%</span>
                    </span>
                  </div>
                  <div style={{height: 24, background:'var(--slate-100)', borderRadius: 6, position:'relative', overflow:'hidden'}}>
                    <div
                      style={{
                        position: 'absolute',
                        top: 0, left: 0, bottom: 0,
                        width: `${pct}%`,
                        background: `linear-gradient(90deg, ${c}, ${c}cc)`,
                        borderRadius: 6,
                        animation: `bar-grow 900ms ${i * 140}ms cubic-bezier(0.33,1,0.68,1) backwards`,
                        transformOrigin: 'left',
                      }}
                    />
                    {i > 0 && (
                      <div style={{position:'absolute',right:`${100-pct}%`,top:-6,bottom:-6,width:2,background:'rgba(15,23,42,0.1)'}} />
                    )}
                  </div>
                  {i > 0 && (() => {
                    const prev = [2184, 2058, 1791, 1567][i - 1];
                    const drop = prev - n;
                    const dropPct = ((drop / prev) * 100).toFixed(1);
                    return (
                      <div style={{
                        fontSize:10,color:'var(--fg-3)',marginTop:4,
                        display:'flex',alignItems:'center',gap:4,
                      }}>
                        <Lic name="trending-down" size={10} />
                        <span>−{drop} ({dropPct}%)</span>
                      </div>
                    );
                  })()}
                </div>
              ))}
              <div style={{
                marginTop:18,paddingTop:14,borderTop:'1px solid var(--divider)',
                display:'flex',justifyContent:'space-between',
              }}>
                <span style={{fontSize:11,color:'var(--fg-3)',fontWeight:600,textTransform:'uppercase',letterSpacing:'0.06em'}}>Conversion</span>
                <span style={{fontSize:13,fontWeight:800,color:'var(--brand-600)'}}>72% end-to-end</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

Object.assign(window, { AnalyticsPage, RevenueChart, DonutChart, DailyBars, Heatmap, NpsGauge });
