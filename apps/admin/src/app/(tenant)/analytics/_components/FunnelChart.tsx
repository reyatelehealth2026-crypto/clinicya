'use client';

import { useEffect, useState } from 'react';
import { getFunnelAction } from '../actions';
import type { FunnelStage } from '../_lib/advancedQueries';

/**
 * FunnelChart.tsx — client port of dashboard.php's `loadFunnel()`/
 * `renderFunnel()` (app/Views/analytics/dashboard.php lines 158-165,
 * 337-366): fetched on mount (not server-rendered), same as the PHP source's
 * `fetch('?action=api_funnel&period=...')` call in its
 * `DOMContentLoaded` handler.
 */
export function FunnelChart({ period }: { period: string }) {
  const [stages, setStages] = useState<FunnelStage[] | null>(null);

  useEffect(() => {
    let cancelled = false;
    getFunnelAction(period).then((data) => {
      if (!cancelled) setStages(data);
    });
    return () => {
      cancelled = true;
    };
  }, [period]);

  if (!stages) {
    return <div className="text-center text-gray-400 py-8 text-sm">กำลังโหลด...</div>;
  }

  return (
    <div className="space-y-3">
      {stages.map((item) => {
        const width = Math.max(item.rate, 20);
        return (
          <div key={item.stage} className="relative">
            <div className="flex items-center justify-between mb-1">
              <span className="text-sm text-gray-600">{item.stage}</span>
              <span className="text-sm font-semibold">{item.count.toLocaleString()}</span>
            </div>
            <div className="h-8 bg-gray-100 rounded-lg overflow-hidden">
              <div
                className="h-full bg-gradient-to-r from-green-500 to-green-400 rounded-lg flex items-center justify-end pr-2 transition-all duration-500"
                style={{ width: `${width}%` }}
              >
                <span className="text-xs text-white font-medium">{item.rate}%</span>
              </div>
            </div>
          </div>
        );
      })}
    </div>
  );
}
