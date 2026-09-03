import { NextResponse, type NextRequest } from 'next/server';
import { sql, type Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { miniappJson, miniappPreflight } from '@/lib/miniapp/cors';
import { TENANT_UNRESOLVED_RESPONSE, TENANT_UNRESOLVED_STATUS, withMiniappTenant } from '@/lib/miniapp/tenant';

/**
 * /api/miniapp/wishlist — port of api/wishlist.php (read in full, 170 lines) for the WRITE-lane batch
 * (mig-api-writes, Phase 3 batch 1): `list` (GET, also the default when `action` is omitted — PHP's
 * switch defaults to 'list'), `toggle` (POST), `remove` (POST). `add`/`check` are explicitly OUT of
 * scope (zero line-mini-app callers, confirmed via grep of src/lib/wishlist-api.ts).
 *
 * ENVELOPE: deliberately NOT the flat member.php/rewards.php shape — see packages/contracts/src/wishlist.ts's
 * doc comment. Every branch below returns exactly what api/wishlist.php's own `echo json_encode([...])`
 * call sites return (no shared `message` key on the plain list path, `error` — not `message` — on
 * failure), always implicit HTTP 200 (no header()/http_response_code() anywhere in the source file).
 *
 * IDENTITY MODEL: no session/auth gating — line_user_id + line_account_id trusted as given, matching
 * the PHP original. TENANCY: see lib/miniapp/tenant.ts's two-phase pin doc comment.
 */

interface ActionResult {
  status: number;
  body: Record<string, unknown>;
}

function queryRecord(url: URL): Record<string, string> {
  return Object.fromEntries(url.searchParams.entries());
}

/**
 * packages/db's mysql2 pool has no `dateStrings: true`, so DATETIME/TIMESTAMP columns hydrate as JS
 * `Date` objects, not PHP PDO's raw `YYYY-MM-DD HH:MM:SS` strings — left unformatted this serializes to
 * a `Z`-suffixed ISO string via `JSON.stringify`, which is NOT what api/wishlist.php actually returns.
 * Same fix already applied in points-history's/health-profile's query.ts (`formatPhpDate()`/
 * `asDateTimeString()`) — mirrored here rather than imported, per this batch's allowed-paths boundary
 * (each miniapp route folder is self-contained).
 */
function asDateTimeString(value: string | Date | null | undefined): string | null {
  if (value === null || value === undefined) return null;
  if (typeof value === 'string') return value;
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())} ${pad(value.getHours())}:${pad(value.getMinutes())}:${pad(value.getSeconds())}`;
}

async function parseJsonBody(request: NextRequest): Promise<Record<string, unknown> | null> {
  try {
    const raw = await request.text();
    if (!raw) return null;
    const parsed: unknown = JSON.parse(raw);
    return parsed && typeof parsed === 'object' ? (parsed as Record<string, unknown>) : null;
  } catch {
    return null;
  }
}

/**
 * Port of `$input[$key] ?? $_GET[$key] ?? $default` — for GET requests `$input` IS `$_REQUEST` (PHP's
 * `json_decode(php://input) ?: $_REQUEST` fallback, since php://input is empty on GET), so `jsonBody`
 * is null there and this collapses to just reading `query`.
 */
function field(jsonBody: Record<string, unknown> | null, query: Record<string, string>, key: string): unknown {
  if (jsonBody && jsonBody[key] !== undefined && jsonBody[key] !== null) return jsonBody[key];
  return query[key];
}

/*
 * NOTE — no `ensureWishlistTable()` here. api/wishlist.php self-creates
 * `user_wishlist` on every request; that DDL is deliberately NOT ported. Per
 * CLAUDE.md ("new code must never auto-create schema") the table is owned by
 * the canonical tenant template
 * (database/migration_2026-05-25_tenant_template.sql), which packages/db's
 * generated types are derived from — so the runtime CREATE was already a
 * no-op on every non-drifted tenant DB.
 */

async function resolveUserId(db: Kysely<TenantDB>, lineUserId: string): Promise<number | null> {
  if (!lineUserId) return null;
  const result = await sql<{ id: number }>`SELECT id FROM users WHERE line_user_id = ${lineUserId}`.execute(db);
  return result.rows[0]?.id ?? null;
}

async function getCurrentPrice(db: Kysely<TenantDB>, productId: number): Promise<number | null> {
  const result = await sql<{ price: number | string | null; sale_price: number | string | null }>`
    SELECT price, sale_price FROM business_items WHERE id = ${productId}
  `.execute(db);
  const product = result.rows[0];
  if (!product) return null;
  // PHP `$product['sale_price'] ?: $product['price']` — falsy sale_price (null/0/'0') falls back to price.
  const salePrice = product.sale_price;
  if (salePrice !== null && salePrice !== undefined && Number(salePrice) !== 0) {
    return Number(salePrice);
  }
  return product.price === null || product.price === undefined ? null : Number(product.price);
}

async function handleToggle(
  db: Kysely<TenantDB>,
  userId: number | null,
  lineUserId: string,
  productId: number,
  lineAccountId: number | null
): Promise<ActionResult> {
  if (!userId || !productId) {
    return { status: 200, body: { success: false, error: 'Missing user or product' } };
  }

  const existsResult = await sql<{ id: number }>`SELECT id FROM user_wishlist WHERE user_id = ${userId} AND product_id = ${productId}`.execute(db);

  if (existsResult.rows.length > 0) {
    await sql`DELETE FROM user_wishlist WHERE user_id = ${userId} AND product_id = ${productId}`.execute(db);
    return { status: 200, body: { success: true, is_favorite: false, message: 'ลบออกจากรายการโปรดแล้ว' } };
  }

  const currentPrice = await getCurrentPrice(db, productId);
  await sql`
    INSERT INTO user_wishlist (user_id, line_user_id, product_id, line_account_id, price_when_added, notify_on_sale)
    VALUES (${userId}, ${lineUserId}, ${productId}, ${lineAccountId}, ${currentPrice}, 1)
  `.execute(db);
  return { status: 200, body: { success: true, is_favorite: true, message: 'เพิ่มรายการโปรดแล้ว' } };
}

async function handleRemove(db: Kysely<TenantDB>, userId: number | null, productId: number): Promise<ActionResult> {
  if (!userId || !productId) {
    return { status: 200, body: { success: false, error: 'Missing user or product' } };
  }
  await sql`DELETE FROM user_wishlist WHERE user_id = ${userId} AND product_id = ${productId}`.execute(db);
  return { status: 200, body: { success: true, message: 'ลบออกจากรายการโปรดแล้ว' } };
}

interface WishlistItemRow {
  id: number;
  user_id: number;
  line_user_id: string | null;
  product_id: number;
  line_account_id: number | null;
  price_when_added: number | string | null;
  notify_on_sale: number | null;
  notify_on_restock: number | null;
  notified_at: string | Date | null;
  created_at: string | Date;
  name: string;
  sku: string | null;
  price: number | string | null;
  sale_price: number | string | null;
  image_url: string | null;
  stock: number | null;
  is_on_sale: number;
  discount_percent: number | string;
}

/** Formats every DATETIME/TIMESTAMP column `w.*` can return — see `asDateTimeString()`'s doc comment. */
function normalizeWishlistItemDates(row: WishlistItemRow): WishlistItemRow {
  return {
    ...row,
    notified_at: asDateTimeString(row.notified_at),
    created_at: asDateTimeString(row.created_at) ?? row.created_at,
  };
}

async function handleList(db: Kysely<TenantDB>, userId: number | null, lineUserId: string): Promise<ActionResult> {
  if (!userId && !lineUserId) {
    return { status: 200, body: { success: true, items: [] } };
  }

  const rows = userId
    ? (
        await sql<WishlistItemRow>`
          SELECT w.*, p.name, p.sku, p.price, p.sale_price, p.image_url, p.stock,
                 CASE WHEN p.sale_price IS NOT NULL AND p.sale_price < w.price_when_added THEN 1 ELSE 0 END as is_on_sale,
                 CASE WHEN p.sale_price IS NOT NULL THEN ROUND((1 - p.sale_price / w.price_when_added) * 100) ELSE 0 END as discount_percent
          FROM user_wishlist w
          JOIN business_items p ON w.product_id = p.id
          WHERE w.user_id = ${userId}
          ORDER BY w.created_at DESC
        `.execute(db)
      ).rows
    : (
        await sql<WishlistItemRow>`
          SELECT w.*, p.name, p.sku, p.price, p.sale_price, p.image_url, p.stock,
                 CASE WHEN p.sale_price IS NOT NULL AND p.sale_price < w.price_when_added THEN 1 ELSE 0 END as is_on_sale,
                 CASE WHEN p.sale_price IS NOT NULL THEN ROUND((1 - p.sale_price / w.price_when_added) * 100) ELSE 0 END as discount_percent
          FROM user_wishlist w
          JOIN business_items p ON w.product_id = p.id
          WHERE w.line_user_id = ${lineUserId}
          ORDER BY w.created_at DESC
        `.execute(db)
      ).rows;

  const items = rows.map(normalizeWishlistItemDates);
  return { status: 200, body: { success: true, items, count: items.length } };
}

async function dispatch(
  db: Kysely<TenantDB>,
  jsonBody: Record<string, unknown> | null,
  query: Record<string, string>
): Promise<ActionResult> {
  const action = String(field(jsonBody, query, 'action') ?? '');
  const lineUserId = String(field(jsonBody, query, 'line_user_id') ?? '');
  const productIdRaw = field(jsonBody, query, 'product_id');
  const productId = productIdRaw === undefined || productIdRaw === null || productIdRaw === '' ? 0 : Number(productIdRaw) || 0;
  const lineAccountIdRaw = field(jsonBody, query, 'line_account_id');
  const lineAccountId = lineAccountIdRaw === undefined || lineAccountIdRaw === null || lineAccountIdRaw === '' ? null : Number(lineAccountIdRaw);

  try {
    const userId = await resolveUserId(db, lineUserId);

    switch (action) {
      case 'toggle':
        return await handleToggle(db, userId, lineUserId, productId, lineAccountId);
      case 'remove':
        return await handleRemove(db, userId, productId);
      case 'list':
      default:
        return await handleList(db, userId, lineUserId);
    }
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return { status: 200, body: { success: false, error: message } };
  }
}

function respond(result: ActionResult): NextResponse {
  return miniappJson(result.body, { status: result.status });
}

export async function OPTIONS(): Promise<NextResponse> {
  return miniappPreflight();
}

export async function GET(request: NextRequest): Promise<NextResponse> {
  const url = new URL(request.url);
  const query = queryRecord(url);

  const resolved = await withMiniappTenant(request, { method: 'GET', query }, ({ db }) => dispatch(db, null, query));

  if (!resolved.ok) {
    return miniappJson(TENANT_UNRESOLVED_RESPONSE, { status: TENANT_UNRESOLVED_STATUS });
  }
  return respond(resolved.value);
}

export async function POST(request: NextRequest): Promise<NextResponse> {
  const url = new URL(request.url);
  const query = queryRecord(url);
  const jsonBody = await parseJsonBody(request);

  const resolved = await withMiniappTenant(request, { method: 'POST', query, jsonBody }, ({ db }) => dispatch(db, jsonBody, query));

  if (!resolved.ok) {
    return miniappJson(TENANT_UNRESOLVED_RESPONSE, { status: TENANT_UNRESOLVED_STATUS });
  }
  return respond(resolved.value);
}
