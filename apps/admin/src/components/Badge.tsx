import type { ReactNode } from 'react';

/**
 * Badge / Chip primitives — small inline pill labels. Not a 1:1 port of a
 * single PHP partial (users.php/user-detail.php inline these as raw
 * `<span style="...">` HTML rather than a reusable component — see
 * users.php's `$lineUserColumns['status']['render']` closure and
 * user-detail.php's tag chips around line 546), consolidated here into one
 * reusable primitive so every future admin list gets the same status/tag
 * chip look for free.
 */
export type BadgeTone = 'success' | 'danger' | 'neutral' | 'primary';

const TONE_CLASS: Record<BadgeTone, string> = {
  success: 'badge badge-success',
  danger: 'badge badge-danger',
  neutral: 'badge badge-neutral',
  primary: 'badge badge-primary',
};

export interface BadgeProps {
  tone?: BadgeTone;
  children: ReactNode;
}

export function Badge({ tone = 'neutral', children }: BadgeProps) {
  return <span className={TONE_CLASS[tone]}>{children}</span>;
}

export interface TagChipProps {
  name: string;
  /** Hex color, e.g. "#3B82F6" — mirrors user_tags.color. */
  color?: string | null;
  onRemove?: () => void;
}

/** A colored pill for a single user_tags row — background/foreground derived from tag.color, matching the inline styles in users.php/user-detail.php. */
export function TagChip({ name, color, onRemove }: TagChipProps) {
  const resolvedColor = color ?? '#3B82F6';
  return (
    <span
      className="tag-chip"
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: 4,
        padding: '4px 10px',
        borderRadius: 9999,
        fontSize: 12,
        fontWeight: 500,
        color: '#ffffff',
        backgroundColor: resolvedColor,
      }}
    >
      {name}
      {onRemove ? (
        <button
          type="button"
          onClick={onRemove}
          aria-label={`Remove tag ${name}`}
          style={{ background: 'transparent', border: 'none', color: 'inherit', cursor: 'pointer', lineHeight: 1 }}
        >
          ×
        </button>
      ) : null}
    </span>
  );
}

/** Active/Blocked pill — mirrors users.php's status column render closure exactly (is_blocked -> Blocked/Active). */
export function UserStatusBadge({ isBlocked }: { isBlocked: boolean }) {
  return isBlocked ? (
    <Badge tone="danger">Blocked</Badge>
  ) : (
    <Badge tone="success">Active</Badge>
  );
}
