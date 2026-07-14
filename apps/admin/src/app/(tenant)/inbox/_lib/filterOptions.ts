import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';

/**
 * filterOptions.ts — SSR data for the sidebar chrome that ISN'T the
 * conversation list itself: the tag/assignee filter dropdown options, the
 * Facebook/TikTok channel-switcher count badges, and the "เพิ่งแอด · ยังไม่ทัก"
 * (new-followers) chip's count. Port of inbox-v2.php lines 155-214 (tags,
 * admins, uncontacted-follower count) and 1012-1023 (platform counts).
 *
 * `hasPlatformColumn` (inbox-v2.php lines 1001-1005, a runtime `SHOW COLUMNS
 * FROM users LIKE 'platform'` probe used to degrade to LINE-only on tenants
 * that predate migration_add_platforms) is NOT reproduced — same
 * intentional simplification (tenant)/users/queries.ts already documents
 * for its own column-existence probes: `packages/db`'s generated TenantDB
 * schema (introspected from the current committed tenant template) already
 * has `users.platform`, so this is not a behavior change on any tenant DB
 * created from that template.
 *
 * NOT ported (out of this batch's scope): `$pointsPerBaht` (inbox-v2.php
 * lines 163-173) — feeds the "ให้แต้ม (ขายหน้าร้าน)" give-points QR modal,
 * which this batch's brief does not list as a component to build (it is a
 * write/checkout-adjacent flow, not part of the read-only conversation-list
 * surface this batch ports).
 */

export interface InboxFilterTag {
  id: number;
  name: string;
  color: string | null;
}

export interface InboxFilterAdmin {
  id: number;
  username: string;
  display_name: string | null;
}

export interface InboxPlatformCounts {
  facebook: number;
  tiktok: number;
}

export interface InboxFilterOptions {
  allTagsForFilter: InboxFilterTag[];
  allAdmins: InboxFilterAdmin[];
  platformCounts: InboxPlatformCounts;
  uncontactedFollowerCount: number;
}

/** Port of inbox-v2.php lines 198-205: `SELECT * FROM user_tags WHERE line_account_id = ? OR line_account_id IS NULL ORDER BY name ASC`. */
export async function getAllTagsForFilter(db: Kysely<TenantDB>, currentBotId: number | null): Promise<InboxFilterTag[]> {
  const result = await sql<InboxFilterTag>`
    SELECT id, name, color
    FROM user_tags
    WHERE line_account_id = ${currentBotId} OR line_account_id IS NULL
    ORDER BY name ASC
  `.execute(db);
  return result.rows;
}

/** Port of inbox-v2.php lines 208-213: `SELECT id, username, display_name FROM admin_users ORDER BY username ASC`. Raw `sql` — `admin_users` has no generated Kysely schema entry (same gap @reya/auth's own admin_users query works around, see packages/auth/src/session.ts). */
export async function getAllAdmins(db: Kysely<TenantDB>): Promise<InboxFilterAdmin[]> {
  const result = await sql<InboxFilterAdmin>`
    SELECT id, username, display_name
    FROM admin_users
    ORDER BY username ASC
  `.execute(db);
  return result.rows;
}

/** Port of inbox-v2.php lines 1012-1023: per-platform conversation-count badges for the channel switcher. Each platform queried independently and defaulted to 0 on failure, mirroring PHP's individual try/catch per platform. */
export async function getPlatformCounts(db: Kysely<TenantDB>): Promise<InboxPlatformCounts> {
  const countFor = async (platform: 'facebook' | 'tiktok'): Promise<number> => {
    try {
      const result = await sql<{ count: number }>`
        SELECT COUNT(*) AS count FROM users u WHERE u.platform = ${platform} AND EXISTS (SELECT 1 FROM messages WHERE user_id = u.id)
      `.execute(db);
      return Number(result.rows[0]?.count ?? 0);
    } catch {
      return 0;
    }
  };

  const [facebook, tiktok] = await Promise.all([countFor('facebook'), countFor('tiktok')]);
  return { facebook, tiktok };
}

/** Port of InboxService::countUncontactedFollowers() (classes/InboxService.php lines 667-683), called from inbox-v2.php lines 157-161. */
export async function countUncontactedFollowers(db: Kysely<TenantDB>, accountId: number): Promise<number> {
  try {
    const result = await sql<{ count: number }>`
      SELECT COUNT(*) AS count FROM (
        SELECT af.user_id
        FROM account_followers af
        JOIN users u ON u.id = af.user_id
        WHERE af.line_account_id = ${accountId} AND af.is_following = 1 AND af.user_id IS NOT NULL
        AND u.line_account_id = ${accountId}
        AND NOT EXISTS (SELECT 1 FROM messages WHERE user_id = af.user_id)
        GROUP BY af.user_id
      ) t
    `.execute(db);
    return Number(result.rows[0]?.count ?? 0);
  } catch {
    return 0;
  }
}

/** Fetches every piece of sidebar-chrome data in parallel. `currentBotId` mirrors `$_SESSION['current_bot_id']` — pass `session.currentBotId` as-is (null allowed, see getAllTagsForFilter's `OR line_account_id IS NULL` fallback); the account-scoped queries (platform counts aside) fall back to `?? 1` internally where PHP does the same. */
export async function getInboxFilterOptions(db: Kysely<TenantDB>, currentBotId: number | null): Promise<InboxFilterOptions> {
  const accountId = currentBotId ?? 1;
  const [allTagsForFilter, allAdmins, platformCounts, uncontactedFollowerCount] = await Promise.all([
    getAllTagsForFilter(db, currentBotId),
    getAllAdmins(db),
    getPlatformCounts(db),
    countUncontactedFollowers(db, accountId),
  ]);
  return { allTagsForFilter, allAdmins, platformCounts, uncontactedFollowerCount };
}
