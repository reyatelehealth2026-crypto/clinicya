import { TagChip } from '@/components/Badge';

/**
 * ChatHeader — port of inbox-v2.php's "Chat Header" block (lines 3178-3227):
 * avatar, effective display name (`custom_display_name` if set, else
 * `display_name` — line 1121), and the user's tags (lines 3202-3207).
 *
 * OUT OF SCOPE for this batch (not silently dropped): the customer-type
 * badge (⚡/💝/📊, lines 3193-3200 — reads `$customerClassification` from
 * `HealthEngine`, a service this batch's brief does not port) and the
 * Ghost/HUD/customer-info action buttons (lines 3210-3226 — all ~19
 * AI-copilot actions are explicitly deferred per the plan's "actions ทีละ
 * ~5" guidance). Those slot back in above this component in a later batch
 * without changing its own contract.
 */

const AVATAR_FALLBACK_SVG =
  "data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 40 40%22%3E%3Ccircle cx=%2220%22 cy=%2220%22 r=%2220%22 fill=%22%23e5e7eb%22/%3E%3Cpath d=%22M20 22c3.3 0 6-2.7 6-6s-2.7-6-6-6-6 2.7-6 6 2.7 6 6 6zm0 3c-4 0-12 2-12 6v3h24v-3c0-4-8-6-12-6z%22 fill=%22%239ca3af%22/%3E%3C/svg%3E";

export interface ChatHeaderTag {
  id: number;
  name: string;
  color: string | null;
}

export interface ChatHeaderUser {
  pictureUrl: string | null;
  displayName: string | null;
  customDisplayName: string | null;
}

/** `custom_display_name` if truthy, else `display_name` — inbox-v2.php line 1121: `$selectedUser['custom_display_name'] ?: $selectedUser['display_name']`. */
export function effectiveDisplayName(user: ChatHeaderUser): string {
  return user.customDisplayName || user.displayName || '';
}

export function ChatHeader({ user, tags }: { user: ChatHeaderUser; tags: ChatHeaderTag[] }) {
  const name = effectiveDisplayName(user);

  return (
    <div className="h-14 bg-white border-b flex items-center justify-between px-4 shadow-sm">
      <div className="flex items-center gap-3">
        {/* eslint-disable-next-line @next/next/no-img-element -- structural port of a plain preview <img>. */}
        <img
          src={user.pictureUrl || AVATAR_FALLBACK_SVG}
          alt=""
          className="w-10 h-10 rounded-full border-2 border-teal-600"
        />
        <div>
          <div className="flex items-center gap-2">
            <h3 className="font-bold text-gray-800">{name}</h3>
          </div>
          <div id="userTags" className="flex gap-1 flex-wrap">
            {tags.map((tag) => (
              <TagChip key={tag.id} name={tag.name} color={tag.color} />
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}
