'use server';

import { requireAnalyticsPageContext } from './_lib/session';
import { getRealTimeStats, getCustomerFunnel, getDashboardStats, getDateRange, type RealTimeStats, type FunnelStage } from './_lib/advancedQueries';

/**
 * actions.ts — Server Actions replacing the 'advanced' tab's own client-side
 * `fetch('?action=api_realtime')` / `fetch('?action=api_funnel')` / CSV
 * `<a href="?action=export">` (app/Controllers/AnalyticsController.php's
 * apiRealtime()/apiFunnel()/export()). These are read-only round-trips
 * (realtime polling, on-mount funnel load, CSV download) reached via a
 * Server Action rather than a new Route Handler — this batch's brief
 * disallows introducing new API endpoints (that's mig-api's territory), and
 * a Server Action satisfies the same "don't leave the page half-PHP" mandate
 * without adding an app/api/** surface.
 */

export async function getRealtimeStatsAction(): Promise<RealTimeStats> {
  const { db, session } = await requireAnalyticsPageContext();
  return getRealTimeStats(db, session.currentBotId);
}

export async function getFunnelAction(period: string): Promise<FunnelStage[]> {
  const { db, session } = await requireAnalyticsPageContext();
  const range = getDateRange(period);
  return getCustomerFunnel(db, session.currentBotId, range);
}

/**
 * Ported from AnalyticsController::export(): a UTF-8 (BOM-prefixed, for
 * Excel) CSV of the same 6 metrics getDashboardStats() feeds the stat cards
 * with. Returns the CSV text (+ suggested filename) rather than streaming a
 * `Content-Disposition` response directly — Server Actions can't set response
 * headers, so the client component that calls this builds the download via
 * a Blob + synthetic `<a>` click, matching the PHP link's net effect (a
 * downloaded `analytics_YYYY-MM-DD.csv`).
 */
export async function exportCsvAction(period: string): Promise<{ filename: string; csv: string }> {
  const { db, session } = await requireAnalyticsPageContext();
  const stats = await getDashboardStats(db, session.currentBotId, period);

  const rows = [
    ['Metric', 'Value', 'Period'],
    ['Total Users', String(stats.users.total), period],
    ['New Users', String(stats.users.new), period],
    ['Active Users', String(stats.users.active), period],
    ['Total Messages', String(stats.messages.total), period],
    ['Total Orders', String(stats.orders.total), period],
    ['Revenue', String(stats.revenue.total), period],
  ];
  const csvBody = rows.map((row) => row.map(csvEscape).join(',')).join('\r\n');
  const csv = '﻿' + csvBody;

  const dateStamp = new Date().toISOString().slice(0, 10);
  return { filename: `analytics_${dateStamp}.csv`, csv };
}

function csvEscape(value: string): string {
  return /[",\r\n]/.test(value) ? `"${value.replace(/"/g, '""')}"` : value;
}
