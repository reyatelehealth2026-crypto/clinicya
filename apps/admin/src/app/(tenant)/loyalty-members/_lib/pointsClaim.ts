import { randomBytes } from 'node:crypto';
import { sql, type Kysely, type Transaction } from 'kysely';
import type { TenantDB } from '@reya/db';
import { lmName } from '../queries';
import { addPoints, calculatePoints, loadPointsSettings } from './loyalty';

/**
 * pointsClaim.ts — local TypeScript port of api/points-claim.php's
 * `give_by_phone` action (handleGiveByPhone(), lines 787-921) and its
 * shared credit core (pcCreditCounterSale(), lines 595-661), reached from
 * loyalty-members.php's "เพิ่มแต้ม / ลูกค้าใหม่" modal
 * (`fetch('api/points-claim.php', {body: {action:'give_by_phone', ...}})`,
 * loyalty-members.php lines 236-275) — this is the mutation the orchestrator's
 * grep on loyalty-members.php itself missed (it's a client-side fetch to a
 * SEPARATE existing PHP endpoint, not inline `$_POST`/`action=` handling in
 * loyalty-members.php), flagged per this batch's brief. Ported here as a
 * Server Action (see ../actions.ts) rather than a new Route Handler.
 *
 * DEFERRED, NOT reproduced (flagged explicitly, not silently dropped):
 *   - pcPushReceipt() — the Flex "receipt" LINE push message sent to a
 *     LINE-linked target after a successful credit (points-claim.php lines
 *     894-904, classes/FlexTemplates.php). Requires a LINE API client;
 *     `packages/line` (plan §1.1) does not exist yet on this branch — LINE
 *     integration ports in Phase 6 per docs/plans/2026-07-12-nextjs-full-
 *     migration-plan.md. The points-crediting DB mutation itself (the part
 *     mig-verify's fixture/row-count parity actually gates on) is complete
 *     and correct without it; only the outbound LINE message is skipped.
 *   - QR/token/`create`/`claim`/`status`/`lookup_phone`/`list_merge_candidates`/
 *     `confirm_merge`/`dismiss_merge` actions in api/points-claim.php — none
 *     of these are called from loyalty-members.php (confirmed by reading the
 *     page's full `<script>` block); only `give_by_phone` and `member_detail`
 *     are. Out of scope for this page port.
 *
 * Everything else — phone normalization, existing-customer-vs-new-ghost
 * resolution (LINE-linked preferred via the same `ORDER BY (line_user_id
 * LIKE 'offline:%') ASC, available_points DESC, id ASC`), ghost name
 * backfill, voucher numbering, the points_claims audit row + points ledger
 * credit (wrapped in one Kysely transaction, mirroring the PHP source's
 * `$db->beginTransaction()/commit()/rollBack()` in pcCreditCounterSale()),
 * and the merge-candidate flag write — is reproduced faithfully.
 */

// ---------------------------------------------------------------------------
// Small helpers (points-claim.php's pc* free functions)
// ---------------------------------------------------------------------------

/** Ported from pcNormalizePhone(). */
export function pcNormalizePhone(raw: string): string {
  let digits = raw.replace(/\D+/g, '');
  if (digits.length === 11 && digits.startsWith('66')) {
    digits = '0' + digits.slice(2);
  }
  return digits;
}

/** Ported from pcIsLineUser(). */
export function pcIsLineUser(lineUserId: string | null | undefined): boolean {
  return !(lineUserId ?? '').startsWith('offline:');
}

/** Ported from pcNormalizePayment(). */
export function pcNormalizePayment(raw: string): 'cash' | 'transfer' | 'card' | 'qr' | null {
  const s = raw.trim().toLowerCase();
  return (['cash', 'transfer', 'card', 'qr'] as const).includes(s as 'cash') ? (s as 'cash' | 'transfer' | 'card' | 'qr') : null;
}

/*
 * NOTE — no `ensurePointsClaimsTable()` / `ensurePointsMergeTable()` here.
 * points-claim.php self-creates both tables on every request; that DDL is
 * deliberately NOT ported. Per CLAUDE.md ("new code must never auto-create
 * schema" / "prefer a versioned database/migration_*.sql file, not page-load
 * auto-create") the schema is owned by the committed, whitelisted migrations:
 *   - `points_claims`            -> database/migration_2026-06-02_points_claims.sql
 *   - `points_merge_candidates`  -> database/migration_2026-06-20_points_phone_members.sql
 * Both are byte-equivalent to the DDL the PHP emitted, so dropping the
 * runtime CREATE changes no schema — only who owns it.
 */

/** Ported from pcGenerateVoucherNo(). */
async function pcGenerateVoucherNo(db: Kysely<TenantDB>, lineAccountId: number): Promise<string> {
  const now = new Date();
  const datePart = `${now.getFullYear()}${String(now.getMonth() + 1).padStart(2, '0')}${String(now.getDate()).padStart(2, '0')}`;
  let seq = 1;
  try {
    const result = await sql<{ count: number }>`
      SELECT COUNT(*) AS count FROM points_claims WHERE line_account_id = ${lineAccountId} AND DATE(created_at) = CURDATE()
    `.execute(db);
    seq = Number(result.rows[0]?.count ?? 0) + 1;
  } catch {
    seq = 1; // UNIQUE is on token, not voucher_no — a dup is harmless, matches PHP.
  }
  return `WI${datePart}-${String(seq).padStart(3, '0')}`;
}

// ---------------------------------------------------------------------------
// give_by_phone (handleGiveByPhone)
// ---------------------------------------------------------------------------

export interface GiveByPhoneInput {
  lineAccountId: number;
  adminUserId: number | null;
  phone: string;
  name: string;
  /** Pharmacist picked a specific existing match (0 = none). */
  userId: number;
  amount: number;
  points: number;
  paymentMethod: string;
}

export interface GiveByPhoneResult {
  success: boolean;
  message: string;
  voucherNo?: string;
  points?: number;
  totalPoints?: number;
  userId?: number;
  customerName?: string;
  hasLine?: boolean;
  isNew?: boolean;
  mergeFlag?: { offlineUserId: number; offlinePoints: number } | null;
}

interface UserMatchRow {
  id: number;
  line_user_id: string;
  display_name: string | null;
  real_name: string | null;
  first_name: string | null;
  last_name: string | null;
  available_points: number | null;
}

const MATCH_COLUMNS = sql`id, line_user_id, display_name, real_name, first_name, last_name, available_points`;

/** Ported from handleGiveByPhone(), points-claim.php lines 787-921 (minus the LINE push — see this file's module doc). */
export async function giveByPhone(db: Kysely<TenantDB>, input: GiveByPhoneInput): Promise<GiveByPhoneResult> {
  const phone = pcNormalizePhone(input.phone);
  if (phone.length < 8) {
    return { success: false, message: 'กรุณากรอกเบอร์ให้ถูกต้อง / Enter a valid phone' };
  }
  const name = input.name.trim();
  const explicitUserId = input.userId > 0 ? input.userId : 0;

  const amount = input.amount > 0 ? input.amount : 0;
  const pointsInput = input.points > 0 ? Math.trunc(input.points) : 0;
  const paymentMethod = pcNormalizePayment(input.paymentMethod);
  if (amount < 0 || pointsInput < 0) {
    return { success: false, message: 'ค่าต้องไม่ติดลบ / Values must be positive' };
  }

  const settings = await loadPointsSettings(db, input.lineAccountId);
  let points: number;
  if (pointsInput > 0) {
    points = pointsInput;
  } else if (amount > 0) {
    points = calculatePoints(settings, amount);
  } else {
    return { success: false, message: 'กรุณากรอกยอดเงินหรือแต้ม / Enter an amount or points' };
  }
  if (points <= 0) {
    return { success: false, message: 'แต้มที่จะให้ต้องมากกว่า 0 / Points to give must be greater than 0' };
  }

  const matchesResult = await sql<UserMatchRow>`
    SELECT ${MATCH_COLUMNS} FROM users
    WHERE line_account_id = ${input.lineAccountId} AND phone = ${phone}
    ORDER BY (line_user_id LIKE 'offline:%') ASC, available_points DESC, id ASC
  `.execute(db);
  const matches = matchesResult.rows;

  let target: UserMatchRow | null = null;
  if (explicitUserId > 0) {
    target = matches.find((m) => m.id === explicitUserId) ?? null;
    if (!target) {
      const vResult = await sql<UserMatchRow>`
        SELECT ${MATCH_COLUMNS} FROM users WHERE id = ${explicitUserId} AND line_account_id = ${input.lineAccountId} LIMIT 1
      `.execute(db);
      target = vResult.rows[0] ?? null;
    }
  }
  if (!target && matches.length > 0) {
    target = matches[0]!; // ordering puts LINE-linked first
  }

  let isNew = false;
  if (!target) {
    const syntheticLineId = `offline:${phone}`;
    const displayName = name !== '' ? name : `ลูกค้า ${phone.slice(-4)}`;
    try {
      const insertResult = await sql`
        INSERT INTO users (line_account_id, line_user_id, display_name, real_name, phone, is_registered, source, registered_at, created_at)
        VALUES (${input.lineAccountId}, ${syntheticLineId}, ${displayName}, ${name !== '' ? name : null}, ${phone}, 1, 'counter', NOW(), NOW())
      `.execute(db);
      const newId = Number(insertResult.insertId ?? 0);
      const rResult = await sql<UserMatchRow>`SELECT ${MATCH_COLUMNS} FROM users WHERE id = ${newId} LIMIT 1`.execute(db);
      target = rResult.rows[0] ?? null;
      isNew = true;
    } catch {
      // unique_line_user race — fetch the row that won.
      const rResult = await sql<UserMatchRow>`
        SELECT ${MATCH_COLUMNS} FROM users WHERE line_account_id = ${input.lineAccountId} AND line_user_id = ${syntheticLineId} LIMIT 1
      `.execute(db);
      target = rResult.rows[0] ?? null;
    }
    if (!target) {
      return { success: false, message: 'ไม่สามารถสร้างลูกค้า / Could not create customer' };
    }
  } else if (name !== '' && !pcIsLineUser(target.line_user_id) && (target.real_name ?? '').trim() === '') {
    try {
      await sql`UPDATE users SET real_name = ${name}, display_name = ${name} WHERE id = ${target.id}`.execute(db);
      target = { ...target, real_name: name, display_name: name };
    } catch {
      // backfill best-effort, matches PHP.
    }
  }

  const creditResult = await creditCounterSale(db, input.lineAccountId, target, amount, points, paymentMethod, input.adminUserId);
  if (!creditResult.ok) {
    return { success: false, message: 'ไม่สามารถให้แต้มได้ / Could not credit points' };
  }

  const targetIsLine = pcIsLineUser(target.line_user_id);
  const mergeFlag = targetIsLine ? await flagMergeForPhone(db, input.lineAccountId, phone, target.id) : null;

  return {
    success: true,
    message: `ให้แต้มสำเร็จ +${points.toLocaleString()} แต้ม`,
    voucherNo: creditResult.voucherNo,
    points,
    totalPoints: creditResult.balance,
    userId: target.id,
    customerName: lmName(target),
    hasLine: targetIsLine,
    isNew,
    mergeFlag,
  };
}

interface CreditResult {
  ok: boolean;
  voucherNo?: string;
  balance?: number;
}

/** Ported from pcCreditCounterSale(): points_claims audit row + LoyaltyPoints::addPoints(), in one transaction. */
async function creditCounterSale(
  db: Kysely<TenantDB>,
  lineAccountId: number,
  user: UserMatchRow,
  amount: number,
  points: number,
  paymentMethod: string | null,
  createdBy: number | null
): Promise<CreditResult> {
  const voucherNo = await pcGenerateVoucherNo(db, lineAccountId);
  const token = randomBytes(24).toString('base64url');

  try {
    const balance = await db.transaction().execute(async (trx: Transaction<TenantDB>) => {
      const insertResult = await sql`
        INSERT INTO points_claims
          (line_account_id, token, voucher_no, points, amount, payment_method, status,
           claimed_by_user_id, claimed_line_user_id, claimed_at, expires_at, created_by)
        VALUES (${lineAccountId}, ${token}, ${voucherNo}, ${points}, ${amount}, ${paymentMethod}, 'claimed',
                ${user.id}, ${user.line_user_id ?? ''}, NOW(), NOW(), ${createdBy})
      `.execute(trx);
      const claimId = Number(insertResult.insertId ?? 0);

      const credited = await addPoints(trx, user.id, points, 'claim', claimId, `รับแต้มจากการซื้อหน้าร้าน #${voucherNo}`, lineAccountId);
      if (!credited.ok) {
        throw new Error('addPoints failed');
      }

      try {
        const ptResult = await sql<{ id: number }>`
          SELECT id FROM points_transactions WHERE user_id = ${user.id} AND reference_type = 'claim' AND reference_id = ${claimId}
          ORDER BY id DESC LIMIT 1
        `.execute(trx);
        const ptId = ptResult.rows[0]?.id;
        if (ptId) {
          await sql`UPDATE points_claims SET points_transaction_id = ${ptId} WHERE id = ${claimId}`.execute(trx);
        }
      } catch {
        // best-effort backfill, matches PHP.
      }

      return credited.newBalance;
    });
    return { ok: true, voucherNo, balance };
  } catch {
    return { ok: false };
  }
}

/** Ported from pcFlagMergeForPhone(). */
async function flagMergeForPhone(
  db: Kysely<TenantDB>,
  lineAccountId: number,
  phone: string,
  lineUserId: number
): Promise<{ offlineUserId: number; offlinePoints: number } | null> {
  try {
    const result = await sql<{ id: number; available_points: number | null }>`
      SELECT id, available_points FROM users
      WHERE line_account_id = ${lineAccountId} AND phone = ${phone} AND line_user_id LIKE 'offline:%' AND available_points > 0
      ORDER BY available_points DESC LIMIT 1
    `.execute(db);
    const ghost = result.rows[0];
    if (!ghost) return null;
    const offlineId = ghost.id;
    if (offlineId === lineUserId) return null;
    const pts = Number(ghost.available_points ?? 0);

    await sql`
      INSERT INTO points_merge_candidates (line_account_id, phone, offline_user_id, line_user_id, offline_points, status)
      VALUES (${lineAccountId}, ${phone}, ${offlineId}, ${lineUserId}, ${pts}, 'pending')
      ON DUPLICATE KEY UPDATE offline_points = VALUES(offline_points),
        status = IF(status = 'merged', 'merged', 'pending'), resolved_at = NULL, resolved_by = NULL
    `.execute(db);

    return { offlineUserId: offlineId, offlinePoints: pts };
  } catch {
    return null;
  }
}

// ---------------------------------------------------------------------------
// member_detail (handleMemberDetail) — read-only
// ---------------------------------------------------------------------------

export interface MemberDetailCustomer {
  userId: number;
  name: string;
  phone: string;
  hasLine: boolean;
  availablePoints: number;
  totalPoints: number;
  usedPoints: number;
  createdAt: string;
}

export interface MemberDetailTransaction {
  type: string;
  points: number;
  balanceAfter: number;
  description: string;
  createdAt: string;
}

export interface MemberDetailResult {
  success: boolean;
  message?: string;
  customer?: MemberDetailCustomer;
  transactions?: MemberDetailTransaction[];
}

/** Ported from handleMemberDetail(), points-claim.php lines 718-775. */
export async function getMemberDetail(db: Kysely<TenantDB>, lineAccountId: number, userId: number): Promise<MemberDetailResult> {
  if (lineAccountId <= 0 || userId <= 0) {
    return { success: false, message: 'ข้อมูลไม่ครบ / Missing parameters' };
  }

  const userResult = await sql<{
    id: number;
    line_user_id: string;
    display_name: string | null;
    real_name: string | null;
    first_name: string | null;
    last_name: string | null;
    phone: string | null;
    available_points: number | null;
    total_points: number | null;
    used_points: number | null;
    created_at: Date | string | null;
  }>`
    SELECT id, line_user_id, display_name, real_name, first_name, last_name, phone,
           available_points, total_points, used_points, created_at
    FROM users WHERE id = ${userId} AND line_account_id = ${lineAccountId} LIMIT 1
  `.execute(db);
  const u = userResult.rows[0];
  if (!u) {
    return { success: false, message: 'ไม่พบลูกค้า / Customer not found' };
  }

  let transactions: MemberDetailTransaction[] = [];
  try {
    const txResult = await sql<{ type: string; points: number; balance_after: number; description: string | null; created_at: Date | string }>`
      SELECT type, points, balance_after, description, created_at
      FROM points_transactions
      WHERE user_id = ${userId} AND line_account_id = ${lineAccountId}
      ORDER BY id DESC LIMIT 50
    `.execute(db);
    transactions = txResult.rows.map((t) => ({
      type: t.type,
      points: Number(t.points),
      balanceAfter: Number(t.balance_after),
      description: t.description ?? '',
      createdAt: String(t.created_at ?? ''),
    }));
  } catch {
    transactions = [];
  }

  return {
    success: true,
    customer: {
      userId: u.id,
      name: lmName(u),
      phone: u.phone ?? '',
      hasLine: pcIsLineUser(u.line_user_id),
      availablePoints: Number(u.available_points ?? 0),
      totalPoints: Number(u.total_points ?? 0),
      usedPoints: Number(u.used_points ?? 0),
      createdAt: String(u.created_at ?? ''),
    },
    transactions,
  };
}
