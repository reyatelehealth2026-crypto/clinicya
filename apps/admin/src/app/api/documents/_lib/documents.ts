import { sql, type Kysely, type Selectable } from 'kysely';
import type { TenantDB } from '@reya/db';
import { calcVAT, computeLineTotal, phpRound } from '@reya/core';

/**
 * documents.ts — TypeScript port of api/documents.php's three shared
 * helpers: `documents_fetch()` (lines 84-98), `documents_norm_items()`
 * (lines 113-157), and `documents_insert()` (lines 159-204). Request-body
 * parsing simplifies to `await request.json()` at the route-handler level
 * (see route.ts) — this is a new endpoint, not bound to PHP's dual
 * `$_POST`/raw-JSON `documents_resolve_input()` (lines 100-111); same
 * simplification precedent `api/inbox/actions/send-message/route.ts`
 * already used.
 *
 * DATE-COLUMN WIRE SHAPE: packages/db's mysql2 pool has no
 * `dateStrings: true`, so business_documents' DATE/DATETIME/TIMESTAMP
 * columns (`issue_date`/`due_date`/`valid_until`/`created_at`/
 * `approved_at`/`cancelled_at`/`updated_at`) hydrate as JS `Date` objects,
 * NOT PHP PDO's raw `YYYY-MM-DD[ HH:MM:SS]` strings — same fact this
 * codebase's other ports already document (see
 * apps/admin/src/app/api/inbox/messages/_lib/query.ts's
 * `toMysqlDateTimeString()` and apps/admin/src/app/(tenant)/articles/_lib/format.ts's
 * module doc). `serializeBusinessDocument()` below converts them back to
 * PHP's raw string shape using the SAME "read with local getters" trick
 * (production/CI pin `TZ=Asia/Bangkok`, and the DB session itself is
 * `SET time_zone='+07:00'` — packages/db/src/tenantPoolRegistry.ts — so a
 * DATE/DATETIME column's stored Bangkok-wall-clock digits round-trip onto
 * the Date object's LOCAL fields unshifted) so the JSON response byte-matches
 * PDO's fetch, not a UTC-shifted ISO string.
 */

// ---------------------------------------------------------------------------
// PHP scalar-cast helpers — small, deliberately duplicated per-feature
// helpers (same convention as api/inbox/actions/notes/route.ts's own
// `intval`/`trimOrEmpty`), not imported cross-feature.
// ---------------------------------------------------------------------------

/**
 * PHP's `isset($arr[$k])` — false for BOTH "key absent" and "key present
 * but null" (unlike `in`/`!== undefined`). Exported: shared verbatim by
 * both `documentsNormItems` below and route.ts's create-handler field
 * mapping (the SAME PHP file/feature, not a cross-feature import).
 */
export function phpIsset(value: unknown): boolean {
  return value !== undefined && value !== null;
}

/**
 * PHP's `empty($v)` — true for unset/null/false/0/0.0/''/'0'/[] (an empty
 * array). Used by route.ts for the `!empty($input['vat_inclusive'])` /
 * `!empty($input['due_date'])` / `!empty($input['valid_until'])` checks in
 * `case 'create':`.
 */
export function phpEmpty(value: unknown): boolean {
  if (value === undefined || value === null || value === false) return true;
  if (value === 0 || value === '0' || value === '') return true;
  if (Array.isArray(value) && value.length === 0) return true;
  return false;
}

/** PHP's `(int)$v` cast — parses a leading integer run, 0 for anything else (including non-numeric strings). Booleans: true->1, false->0. */
export function phpIntCast(value: unknown): number {
  if (typeof value === 'number') return Math.trunc(value);
  if (typeof value === 'boolean') return value ? 1 : 0;
  if (typeof value === 'string') {
    const match = /^\s*[+-]?\d+/.exec(value);
    return match ? Number.parseInt(match[0], 10) : 0;
  }
  return 0;
}

/** PHP's `(float)$v` cast — parses a leading float-literal run (`parseFloat` matches this closely), 0 for anything else. Booleans: true->1.0, false->0.0. */
export function phpFloatCast(value: unknown): number {
  if (typeof value === 'number') return value;
  if (typeof value === 'boolean') return value ? 1 : 0;
  if (typeof value === 'string') {
    const parsed = Number.parseFloat(value);
    return Number.isFinite(parsed) ? parsed : 0;
  }
  return 0;
}

/** PHP's `(string)$v` cast for the scalar shapes this endpoint's JSON body realistically carries. */
export function phpStringCast(value: unknown): string {
  if (typeof value === 'string') return value;
  if (typeof value === 'number' || typeof value === 'boolean') return String(value);
  if (value === null || value === undefined) return '';
  return String(value);
}

/**
 * PHP's `substr($str, 0, $maxBytes)` — BYTE-oriented truncation (not
 * character-oriented like `mb_substr`), matching the literal PHP source's
 * `substr(...)` calls throughout `documents_norm_items`/the create/update
 * actions. Implemented via `Buffer` so a cut mid-multi-byte UTF-8 sequence
 * behaves the same way Node would round-trip it (trailing incomplete bytes
 * decode to U+FFFD) — this only differs from PHP's raw byte truncation in
 * the rare case of a name landing exactly on a multi-byte boundary, which
 * no acceptance criterion here exercises.
 */
export function phpByteSubstr(value: string, maxBytes: number): string {
  const buf = Buffer.from(value, 'utf8');
  if (buf.length <= maxBytes) return value;
  return buf.subarray(0, maxBytes).toString('utf8');
}

// ---------------------------------------------------------------------------
// Date-column JSON serialization — see module doc.
// ---------------------------------------------------------------------------

function pad2(n: number): string {
  return String(n).padStart(2, '0');
}

/** `YYYY-MM-DD` — matches PDO's raw fetch of a SQL `DATE` column. */
function toDateOnlyString(value: Date): string {
  return `${value.getFullYear()}-${pad2(value.getMonth() + 1)}-${pad2(value.getDate())}`;
}

/** `YYYY-MM-DD HH:MM:SS` — matches PDO's raw fetch of a SQL `DATETIME`/`TIMESTAMP` column. */
function toDateTimeString(value: Date): string {
  return `${toDateOnlyString(value)} ${pad2(value.getHours())}:${pad2(value.getMinutes())}:${pad2(value.getSeconds())}`;
}

export type BusinessDocumentRow = Selectable<TenantDB['business_documents']>;
export type BusinessDocumentItemRow = Selectable<TenantDB['business_document_items']>;

/** JSON-serializable form of BusinessDocumentRow — date columns as PHP-style strings, not JS Date objects. */
export type BusinessDocumentJson = Omit<
  BusinessDocumentRow,
  'issue_date' | 'due_date' | 'valid_until' | 'created_at' | 'approved_at' | 'cancelled_at' | 'updated_at'
> & {
  issue_date: string;
  due_date: string | null;
  valid_until: string | null;
  created_at: string;
  approved_at: string | null;
  cancelled_at: string | null;
  updated_at: string;
};

export interface BusinessDocumentWithItemsJson extends BusinessDocumentJson {
  items: BusinessDocumentItemRow[];
}

function serializeBusinessDocument(row: BusinessDocumentRow): BusinessDocumentJson {
  return {
    ...row,
    issue_date: toDateOnlyString(row.issue_date),
    due_date: row.due_date ? toDateOnlyString(row.due_date) : null,
    valid_until: row.valid_until ? toDateOnlyString(row.valid_until) : null,
    created_at: toDateTimeString(row.created_at),
    approved_at: row.approved_at ? toDateTimeString(row.approved_at) : null,
    cancelled_at: row.cancelled_at ? toDateTimeString(row.cancelled_at) : null,
    updated_at: toDateTimeString(row.updated_at),
  };
}

// ---------------------------------------------------------------------------
// documents_fetch() — api/documents.php lines 84-98
// ---------------------------------------------------------------------------

/**
 * Port of:
 * ```php
 * function documents_fetch(PDO $db, int $lineAccountId, int $id): ?array
 * {
 *     $stmt = $db->prepare('SELECT * FROM business_documents WHERE id = ? AND line_account_id = ?');
 *     $stmt->execute([$id, $lineAccountId]);
 *     $doc = $stmt->fetch(PDO::FETCH_ASSOC);
 *     if (!$doc) return null;
 *     $stmt = $db->prepare('SELECT * FROM business_document_items WHERE document_id = ? ORDER BY line_no ASC, id ASC');
 *     $stmt->execute([$id]);
 *     $doc['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
 *     return $doc;
 * }
 * ```
 */
export async function documentsFetch(
  db: Kysely<TenantDB>,
  lineAccountId: number,
  id: number
): Promise<BusinessDocumentWithItemsJson | null> {
  const doc = await db
    .selectFrom('business_documents')
    .selectAll()
    .where('id', '=', id)
    .where('line_account_id', '=', lineAccountId)
    .executeTakeFirst();
  if (!doc) return null;

  const items = await db
    .selectFrom('business_document_items')
    .selectAll()
    .where('document_id', '=', id)
    .orderBy('line_no', 'asc')
    .orderBy('id', 'asc')
    .execute();

  return { ...serializeBusinessDocument(doc), items };
}

// ---------------------------------------------------------------------------
// documents_norm_items() — api/documents.php lines 113-157
// ---------------------------------------------------------------------------

export interface NormalizedDocumentItem {
  line_no: number;
  product_id: number | null;
  product_sku: string | null;
  product_name: string;
  description: string | null;
  quantity: number;
  unit: string | null;
  unit_price: number;
  discount_percent: number;
  discount_amount: number;
  line_total: number;
}

export interface NormalizedDocumentItems {
  items: NormalizedDocumentItem[];
  subtotal: number;
  discount_amount: number;
  vat_amount: number;
  total_amount: number;
}

/**
 * Port of `documents_norm_items(array $rawItems, float $vatRate, bool $vatInclusive): array`.
 * An item with a blank `product_name` (after trim) or a `quantity <= 0` is
 * silently skipped — matches PHP's `continue` (not an error). `quantity`
 * defaults to `1` when absent/null (`$it['quantity'] ?? 1`), NOT 0 — PHP's
 * `??` only falls back on null/unset, so an explicit `0` is used as-is
 * (and then skipped by the `<= 0` guard).
 */
export function documentsNormItems(rawItems: unknown[], vatRate: number, vatInclusive: boolean): NormalizedDocumentItems {
  const items: NormalizedDocumentItem[] = [];
  let lineNo = 1;
  let subtotal = 0;
  let totalDiscount = 0;

  for (const raw of rawItems) {
    const it = raw !== null && typeof raw === 'object' ? (raw as Record<string, unknown>) : {};

    const name = phpStringCast(phpIsset(it.product_name) ? it.product_name : '').trim();
    if (name === '') continue;

    const qty = phpFloatCast(phpIsset(it.quantity) ? it.quantity : 1);
    if (qty <= 0) continue;

    const unitPrice = phpFloatCast(phpIsset(it.unit_price) ? it.unit_price : 0);
    const discPct = phpFloatCast(phpIsset(it.discount_percent) ? it.discount_percent : 0);
    const discAmt = phpFloatCast(phpIsset(it.discount_amount) ? it.discount_amount : 0);
    const lineTotal = computeLineTotal(qty, unitPrice, discPct, discAmt);

    items.push({
      line_no: lineNo++,
      product_id: phpIsset(it.product_id) && phpIntCast(it.product_id) > 0 ? phpIntCast(it.product_id) : null,
      product_sku: phpIsset(it.product_sku) ? phpByteSubstr(phpStringCast(it.product_sku), 100) : null,
      product_name: phpByteSubstr(name, 255),
      description: phpIsset(it.description) ? phpStringCast(it.description) : null,
      quantity: phpRound(qty, 2),
      unit: phpIsset(it.unit) ? phpByteSubstr(phpStringCast(it.unit), 50) : null,
      unit_price: phpRound(unitPrice, 2),
      discount_percent: phpRound(discPct, 2),
      discount_amount: phpRound(discAmt, 2),
      line_total: lineTotal,
    });
    subtotal += qty * unitPrice;
    totalDiscount += qty * unitPrice - lineTotal;
  }

  const totals = calcVAT(subtotal - totalDiscount, vatRate, vatInclusive);
  return {
    items,
    subtotal: phpRound(subtotal, 2),
    discount_amount: phpRound(totalDiscount, 2),
    vat_amount: totals.vat,
    total_amount: totals.total,
  };
}

// ---------------------------------------------------------------------------
// documents_insert() — api/documents.php lines 159-204
// ---------------------------------------------------------------------------

/**
 * Mirrors the exact 26-column `$doc` array `documents_insert()` expects
 * (lines 161-169) — every field PHP's `$doc[$c] ?? null` could read.
 * Callers (route.ts's create/convert-equivalent logic) build this fully,
 * matching how the PHP source always constructs a complete array literal
 * before calling `documents_insert()`.
 */
export interface DocumentInsertInput {
  line_account_id: number;
  doc_type: string;
  doc_number: string;
  ref_transaction_id: number | null;
  ref_doc_id: number | null;
  customer_user_id: number | null;
  customer_name: string | null;
  customer_tax_id: string | null;
  customer_branch_code: string | null;
  customer_address: string | null;
  customer_phone: string | null;
  customer_email: string | null;
  issue_date: string;
  due_date: string | null;
  valid_until: string | null;
  subtotal: number;
  discount_amount: number;
  vat_rate: number;
  vat_amount: number;
  total_amount: number;
  payment_method: string | null;
  payment_ref: string | null;
  status: string;
  note: string | null;
  internal_note: string | null;
  created_by: number | null;
}

/**
 * Port of `documents_insert(PDO $db, array $doc, array $items): int`.
 * Uses `sql` raw-template inserts (positional `?` params, same column
 * order as the PHP source) rather than Kysely's typed
 * `.insertInto().values()` builder — deliberate: `business_documents`'
 * DATE columns (`issue_date`/`due_date`/`valid_until`) are typed `Date` on
 * the Insertable side (packages/db's generated types), and PHP never
 * constructs a `DateTime` for these either — it binds the raw
 * `YYYY-MM-DD` string straight through the prepared statement and lets
 * MySQL parse the date literal. Binding the same plain strings here via
 * `sql` is the MORE faithful port, not a shortcut.
 *
 * `db` accepts either a plain `Kysely<TenantDB>` or an open
 * `Transaction<TenantDB>` — callers share one transaction across
 * `genDocNumber()` + this insert (create route) or run standalone
 * (none of this round's routes do that, but the signature stays general).
 */
export async function documentsInsert(db: Kysely<TenantDB>, doc: DocumentInsertInput, items: NormalizedDocumentItem[]): Promise<number> {
  const insertResult = await sql`
    INSERT INTO business_documents (
      line_account_id, doc_type, doc_number, ref_transaction_id, ref_doc_id,
      customer_user_id, customer_name, customer_tax_id, customer_branch_code,
      customer_address, customer_phone, customer_email,
      issue_date, due_date, valid_until,
      subtotal, discount_amount, vat_rate, vat_amount, total_amount,
      payment_method, payment_ref,
      status, note, internal_note, created_by
    ) VALUES (
      ${doc.line_account_id}, ${doc.doc_type}, ${doc.doc_number}, ${doc.ref_transaction_id}, ${doc.ref_doc_id},
      ${doc.customer_user_id}, ${doc.customer_name}, ${doc.customer_tax_id}, ${doc.customer_branch_code},
      ${doc.customer_address}, ${doc.customer_phone}, ${doc.customer_email},
      ${doc.issue_date}, ${doc.due_date}, ${doc.valid_until},
      ${doc.subtotal}, ${doc.discount_amount}, ${doc.vat_rate}, ${doc.vat_amount}, ${doc.total_amount},
      ${doc.payment_method}, ${doc.payment_ref},
      ${doc.status}, ${doc.note}, ${doc.internal_note}, ${doc.created_by}
    )
  `.execute(db);
  const docId = Number(insertResult.insertId ?? 0);

  if (items.length > 0) {
    for (const it of items) {
      await sql`
        INSERT INTO business_document_items
          (document_id, line_no, product_id, product_sku, product_name, description,
           quantity, unit, unit_price, discount_percent, discount_amount, line_total)
        VALUES (${docId}, ${it.line_no}, ${it.product_id}, ${it.product_sku}, ${it.product_name}, ${it.description},
                ${it.quantity}, ${it.unit}, ${it.unit_price}, ${it.discount_percent}, ${it.discount_amount}, ${it.line_total})
      `.execute(db);
    }
  }

  return docId;
}
