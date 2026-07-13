import { EmptyState } from '@/components/EmptyState';
import type { SegmentRow } from '../queries';

/** SegmentsList.tsx — port of marketing-hub.php's segments list (`renderSegmentsList()`, lines 138-155). Static — no interactivity in the PHP source. */
export function SegmentsList({ segments }: { segments: SegmentRow[] }) {
  if (segments.length === 0) {
    return <EmptyState heading="No segments" />;
  }

  return (
    <div className="space-y-2">
      {segments.map((s) => (
        <div key={s.id} className="flex items-center justify-between p-2 bg-gray-50 rounded-md">
          <div>
            <p className="font-medium text-sm">{s.name}</p>
            <p className="text-xs text-gray-500">{s.description}</p>
          </div>
          <span className="text-xs px-2 py-0.5 rounded bg-blue-100 text-blue-700">{s.count}</span>
        </div>
      ))}
    </div>
  );
}
