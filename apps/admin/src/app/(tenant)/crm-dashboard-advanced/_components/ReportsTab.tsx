/**
 * ReportsTab.tsx — Server Component port of reports.php. reports.php makes
 * ZERO `crmApi()` calls anywhere (confirmed by reading the full 83-line
 * file) — its "Generate Report" cards call `generateReport(type)`, a purely
 * client-side function that fakes a 1-second "Generating..." spinner via
 * `setTimeout` and then renders a static "Report generated" card with a
 * Download button that downloads nothing. It never calls `report_sales` or
 * `report_customers` (both unreachable, unimplemented stubs in
 * CRMDashboardService.php regardless — see queries.ts's module doc).
 *
 * SIMPLIFICATION flagged in the build report: the fake spinner/"generated"
 * card round-trip is cosmetic-only JS busywork with no real backend
 * behavior to preserve (no data is fetched, no file is produced) — this
 * renders the report-type cards as plain, non-interactive Server Component
 * markup instead of adding a client boundary to replay a fake timer. The 3
 * card types (Sales/Customer/Team Performance) and the "Select a report
 * type above to generate" placeholder copy are reproduced verbatim.
 */
const REPORT_TYPES = [
  { icon: '📈', label: 'Sales Report', sub: 'Revenue & deals', color: 'bg-blue-100 text-blue-600' },
  { icon: '👥', label: 'Customer Report', sub: 'Acquisition & retention', color: 'bg-green-100 text-green-600' },
  { icon: '💼', label: 'Team Performance', sub: 'Individual metrics', color: 'bg-purple-100 text-purple-600' },
];

export function ReportsTab() {
  return (
    <div>
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
        {REPORT_TYPES.map((r) => (
          <div key={r.label} className="bg-white border border-gray-200 rounded-md p-3">
            <div className="flex items-center gap-3">
              <div className={`w-10 h-10 rounded-md flex items-center justify-center text-lg ${r.color}`}>{r.icon}</div>
              <div>
                <div className="font-semibold">{r.label}</div>
                <div className="text-xs text-gray-500">{r.sub}</div>
              </div>
            </div>
          </div>
        ))}
      </div>

      <div className="bg-white border border-gray-200 rounded-lg overflow-hidden">
        <div className="bg-gray-50 border-b border-gray-200 px-3.5 py-2.5 font-semibold text-sm">Generated Reports</div>
        <div className="p-8 text-center text-gray-400">
          <div className="text-4xl mb-2">📄</div>
          <p>Select a report type above to generate</p>
        </div>
      </div>
    </div>
  );
}
