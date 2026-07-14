/**
 * QuickReplyPreview — port of the quick-reply preview strip in
 * inbox-v2.php's SSR message renderer (lines 3326-3365). Display-only: PHP
 * renders these as inert `<span>`s (no click handler), same here.
 */

export interface QuickReplyItem {
  action?: {
    type?: string;
    label?: string;
    [key: string]: unknown;
  };
  [key: string]: unknown;
}

const ICONS: Record<string, string> = {
  message: '💬',
  uri: '🔗',
  postback: '📤',
  datetimepicker: '📅',
  camera: '📷',
  cameraRoll: '🖼️',
  location: '📍',
};

export function QuickReplyPreview({ items }: { items: QuickReplyItem[] }) {
  if (items.length === 0) return null;

  return (
    <div className="quick-reply-preview flex flex-wrap gap-1 mt-2" style={{ maxWidth: '100%' }}>
      {items.map((item, i) => {
        const action = item.action ?? {};
        const label = action.label ?? '';
        const actionType = action.type ?? 'message';
        const icon = ICONS[actionType] ?? '';
        return (
          // eslint-disable-next-line react/no-array-index-key -- LINE quickReply items carry no stable id.
          <span key={i} className="quick-reply-btn" title={actionType}>
            {icon} {label}
          </span>
        );
      })}
    </div>
  );
}
