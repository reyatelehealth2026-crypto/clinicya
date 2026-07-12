'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { exportCsvAction } from '../actions';

/**
 * AdvancedControls.tsx — client port of dashboard.php's period `<select>`
 * (`onchange="changePeriod(this.value)"` -> `window.location.href =
 * '?period=' + period`) and its Export link (`?action=export&period=...`).
 * Combined into one small client island so the period value only has to be
 * tracked once.
 */
const PERIOD_OPTIONS: { value: string; label: string }[] = [
  { value: '24h', label: '24 ชั่วโมง' },
  { value: '7d', label: '7 วัน' },
  { value: '30d', label: '30 วัน' },
  { value: '90d', label: '90 วัน' },
];

export function AdvancedControls({ period }: { period: string }) {
  const router = useRouter();
  const [exporting, setExporting] = useState(false);

  function changePeriod(next: string) {
    router.push(`/analytics?tab=advanced&period=${encodeURIComponent(next)}`);
  }

  async function handleExport() {
    setExporting(true);
    try {
      const { filename, csv } = await exportCsvAction(period);
      const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
    } finally {
      setExporting(false);
    }
  }

  return (
    <div className="flex items-center gap-3">
      <select
        value={period}
        onChange={(e) => changePeriod(e.target.value)}
        className="px-4 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-green-500 focus:border-transparent"
        aria-label="ช่วงเวลา"
      >
        {PERIOD_OPTIONS.map((opt) => (
          <option key={opt.value} value={opt.value}>
            {opt.label}
          </option>
        ))}
      </select>
      <button type="button" onClick={() => router.refresh()} className="px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-lg text-sm transition">
        รีเฟรช
      </button>
      <button type="button" onClick={handleExport} disabled={exporting} className="px-4 py-2 bg-green-500 hover:bg-green-600 text-white rounded-lg text-sm transition disabled:opacity-60">
        {exporting ? 'กำลัง Export...' : 'Export'}
      </button>
    </div>
  );
}
