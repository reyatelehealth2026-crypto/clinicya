'use client';

import { useEffect, useState } from 'react';
import { getRealtimeStatsAction } from '../actions';
import type { RealTimeStats } from '../_lib/advancedQueries';

/**
 * RealtimeBar.tsx — client port of dashboard.php's realtime stats strip +
 * its `setInterval(refreshRealtime, 60000)` auto-refresh (app/Views/
 * analytics/dashboard.php lines 30-56, 233-239, 376-387). Polls
 * getRealtimeStatsAction() (a Server Action, not a new Route Handler) every
 * 60 seconds, matching the PHP source's interval exactly.
 */
export function RealtimeBar({ initial }: { initial: RealTimeStats }) {
  const [stats, setStats] = useState<RealTimeStats>(initial);

  useEffect(() => {
    const id = setInterval(() => {
      getRealtimeStatsAction()
        .then(setStats)
        .catch(() => {
          /* best-effort refresh, matches PHP's un-handled fetch().catch-less behavior */
        });
    }, 60000);
    return () => clearInterval(id);
  }, []);

  return (
    <div className="bg-gradient-to-r from-green-500 to-green-600 rounded-xl p-4 mb-6 text-white">
      <div className="flex items-center justify-between flex-wrap gap-4">
        <div className="flex items-center gap-2">
          <span className="w-2 h-2 bg-white rounded-full animate-pulse" />
          <span className="text-sm font-medium">Real-time</span>
        </div>
        <div className="flex items-center gap-8 text-sm">
          <div>
            <span className="opacity-80">Active Users:</span>
            <span className="font-bold ml-1">{stats.activeUsers}</span>
          </div>
          <div>
            <span className="opacity-80">Messages/hr:</span>
            <span className="font-bold ml-1">{stats.messagesPerHour}</span>
          </div>
          <div>
            <span className="opacity-80">Orders Today:</span>
            <span className="font-bold ml-1">{stats.ordersToday}</span>
          </div>
          <div>
            <span className="opacity-80">Revenue Today:</span>
            <span className="font-bold ml-1">฿{stats.revenueToday.toLocaleString()}</span>
          </div>
        </div>
      </div>
    </div>
  );
}
