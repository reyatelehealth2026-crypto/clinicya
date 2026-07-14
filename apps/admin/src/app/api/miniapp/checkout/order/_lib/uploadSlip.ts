import { existsSync, promises as fs } from 'node:fs';
import path from 'node:path';
import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { phpFalsy } from './phpCompat';
import { notifyTelegramPayment, sendReceiptMessage, type ReceiptOrderRow } from './notify';

/**
 * uploadSlip.ts — port of api/checkout.php's handleUploadSlip() (action=upload_slip,
 * multipart/form-data, L1733-1863). Read the full function before editing this file.
 *
 * SIMPLIFICATION (flagged, schema-verification-confirmed — this batch's acceptance criteria):
 * `payment_slips` INSERT ports ONLY the "with user_id" shape (PHP's Level 1). packages/db's generated
 * tenant-db.d.ts confirms `PaymentSlips.user_id` is unconditionally present on the committed schema, so
 * PHP's `catch` fallback INSERT (without user_id, L1819-1826) is dead weight there — not ported.
 *
 * PHYSICAL UPLOAD PATH: writes to `<repo-root>/uploads/slips/` — the SAME shared directory
 * api/checkout.php writes to (`__DIR__ . '/../uploads/slips/'`, __DIR__ being `<repo-root>/api`), NOT a
 * per-tenant TenantFileStorage bucket, so both stacks keep serving/reading the same on-disk slips during
 * strangler coexistence. See resolveSlipsUploadDir()'s own doc comment for the resolution strategy.
 *
 * image_url is built from the INCOMING request's own scheme+host (`origin`, computed by route.ts from
 * `new URL(request.url)`), never a hardcoded BASE_URL constant — matches PHP's documented bug-fix comment
 * at L1797-1806 (the hardcoded BASE_URL points at a single tenant subdomain whose docroot does not
 * contain this shared upload dir, which made the slip image 404).
 *
 * `qr_data` (client-side QR-decoded text, sent by line-mini-app/src/lib/shop-api.ts's
 * uploadPaymentSlip()) is accepted-and-ignored, matching PHP (grep-verified: `qr_data` never appears
 * anywhere in api/checkout.php — no slip auto-verification exists to port). `user_id` (also sent by the
 * client) is likewise accepted-and-ignored — PHP reads it only for a debug `error_log()` line, never for
 * any logic.
 *
 * Does NOT mark the order 'paid'/status='completed' — see PHP's own L1829-1835 comment: the pharmacist
 * hasn't verified the slip yet; only `transactions.updated_at` is touched. Not wrapped in a DB
 * transaction — handleUploadSlip() in PHP has no `$db->beginTransaction()` either (each statement
 * autocommits individually, same as this port's sequential `.execute(db)` calls).
 */

export interface UploadSlipActionResult {
  status: number;
  body: Record<string, unknown>;
}

/** Port of api/checkout.php's local `jsonResponse($success, $message, $data = [])` — always HTTP 200. */
function ok(success: boolean, message: string, data: Record<string, unknown> = {}): UploadSlipActionResult {
  return { status: 200, body: { success, message, ...data } };
}

const ALLOWED_MIME_TYPES = new Set(['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
const MAX_SLIP_BYTES = 5 * 1024 * 1024;

/**
 * Resolves `<repo-root>/uploads/slips/`. An explicit `CHECKOUT_SLIPS_UPLOAD_DIR` env override wins if
 * set (for deployments where the Node process's cwd isn't physically under this repo tree at all, e.g. a
 * container image built from a standalone artifact with no sibling git checkout). Otherwise walks upward
 * from `process.cwd()` looking for `pnpm-workspace.yaml` (the repo-root marker) — this correctly finds
 * the real repo root whether cwd is `apps/admin` itself (`pnpm --filter admin dev/start`) or a nested
 * `.next/standalone/...` build-output copy, since that copy is still physically nested inside the real
 * repo tree (`<repoRoot>/apps/admin/.next/standalone/...`) and `..` traversal reaches the same real
 * filesystem root either way.
 */
function resolveSlipsUploadDir(): string {
  const override = process.env.CHECKOUT_SLIPS_UPLOAD_DIR;
  if (override && override.trim() !== '') {
    return override;
  }
  let dir = process.cwd();
  for (let i = 0; i < 12; i++) {
    if (existsSync(path.join(dir, 'pnpm-workspace.yaml'))) {
      return path.join(dir, 'uploads', 'slips');
    }
    const parent = path.dirname(dir);
    if (parent === dir) {
      break;
    }
    dir = parent;
  }
  // Documented limitation: no pnpm-workspace.yaml found anywhere in the ancestry chain — fall back to
  // cwd-relative rather than failing outright. Set CHECKOUT_SLIPS_UPLOAD_DIR explicitly in any deployment
  // where this matters.
  return path.join(process.cwd(), 'uploads', 'slips');
}

/** `pathinfo($name, PATHINFO_EXTENSION) ?: 'jpg'`. */
function phpFileExtensionOrJpg(name: string): string {
  const base = name.split(/[\\/]/).pop() ?? '';
  const dot = base.lastIndexOf('.');
  const ext = dot > -1 ? base.slice(dot + 1) : '';
  return ext !== '' ? ext : 'jpg';
}

export async function handleUploadSlip(db: Kysely<TenantDB>, form: FormData, origin: string): Promise<UploadSlipActionResult> {
  const orderIdRaw = form.get('order_id');
  if (phpFalsy(orderIdRaw)) {
    return ok(false, 'Order ID required');
  }
  const orderId = typeof orderIdRaw === 'string' ? Number.parseInt(orderIdRaw, 10) : Number(orderIdRaw);

  const file = form.get('slip');
  if (!(file instanceof File) || file.size === 0) {
    return ok(false, 'No file uploaded');
  }

  const mime = file.type || '';
  if (!ALLOWED_MIME_TYPES.has(mime)) {
    return ok(false, 'Invalid file type');
  }
  if (file.size > MAX_SLIP_BYTES) {
    return ok(false, 'File too large (max 5MB)');
  }

  // Scoped to the current tenant DB only — no cross-tenant fallback scan (see PHP's own L1768-1772
  // security comment: that used to be an IDOR, letting a caller upload a slip onto any tenant's order by
  // guessing the sequential id).
  const orderResult = await sql<ReceiptOrderRow>`SELECT * FROM transactions WHERE id = ${orderId}`.execute(db);
  const order = orderResult.rows[0];
  if (!order) {
    return ok(false, 'Order not found');
  }

  const uploadDir = resolveSlipsUploadDir();
  await fs.mkdir(uploadDir, { recursive: true });

  const ext = phpFileExtensionOrJpg(file.name || '');
  const filename = `slip_${order.order_number}_${Math.floor(Date.now() / 1000)}.${ext}`;
  const filepath = path.join(uploadDir, filename);

  const buffer = Buffer.from(await file.arrayBuffer());
  await fs.writeFile(filepath, buffer);

  const imageUrl = `${origin}/uploads/slips/${filename}`;

  // payment_slips insert — Level 1 (with user_id) ONLY, see this file's module doc.
  await sql`
    INSERT INTO payment_slips (order_id, transaction_id, user_id, image_url, status)
    VALUES (${orderId}, ${orderId}, ${order.user_id}, ${imageUrl}, 'pending')
  `.execute(db);

  // Do NOT mark the order 'paid'/status='completed' here — see this file's module doc. Just touch updated_at.
  await sql`UPDATE transactions SET updated_at = NOW() WHERE id = ${orderId}`.execute(db);

  // LINE receipt push + Telegram notify — best-effort, each independently try/catch-swallowed inside its
  // own function (see ./notify.ts); .catch() here is defense-in-depth only, matching PHP's call-site
  // shape (PHP doesn't wrap these calls either, since the callees never throw).
  await sendReceiptMessage(db, order, imageUrl).catch(() => false);

  const slipUserResult = await sql<{ display_name: string | null }>`SELECT display_name FROM users WHERE id = ${order.user_id}`.execute(db);
  const slipUser = slipUserResult.rows[0] ?? {};
  await notifyTelegramPayment(db, orderId, order.order_number, imageUrl, slipUser).catch(() => false);

  return ok(true, 'Slip uploaded', { image_url: imageUrl });
}
