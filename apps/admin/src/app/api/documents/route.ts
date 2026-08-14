import { sql, type RawBuilder } from 'kysely';
import { NextResponse, type NextRequest } from 'next/server';
import { calcVAT, docStatusLabel, docTypeLabel, formatThaiDate, genDocNumber, isDocType, type DocType } from '@reya/core';
import {
  documentsInsert,
  documentsNormItems,
  phpByteSubstr,
  phpEmpty,
  phpFloatCast,
  phpIntCast,
  phpIsset,
  phpStringCast,
  documentsFetch,
  type DocumentInsertInput,
} from './_lib/documents';
import { resolveDocumentsApiContext } from './_lib/session';

/**
 * GET/POST /api/documents — port of api/documents.php's `case 'list':`
 * (lines 212-279, GET) and `case 'create':` (lines 297-377, POST).
 *
 * ONE ROUTE, TWO PHP ACTIONS: PHP dispatches both on a single URL via
 * `?action=list`/`?action=create` + an explicit `$method !== 'GET'`/`!==
 * 'POST'` guard inside each case (returning `{success:false,error:'method'}`,
 * 405). The Next App Router already enforces method<->handler pairing
 * structurally (a GET here can only ever reach the `GET` export, a POST
 * only `POST`) — those in-case guards are therefore unreachable dead code
 * once ported and are NOT replicated; an unimplemented verb here (PUT,
 * DELETE, ...) gets Next's own default 405, which is the same net
 * behavior PHP's guard achieved for those cases.
 *
 * OUT OF SCOPE THIS ROUND (per the brief): `update`/`approve`/`cancel`/
 * `convert`/`pdf`/`export_csv` (api/documents.php lines 380-688) — not
 * ported here, not stubbed with a placeholder route either.
 */

const STATUSES = ['pending_approval', 'approved', 'cancelled'] as const;
type DocStatus = (typeof STATUSES)[number];
function isKnownStatus(value: string): value is DocStatus {
  return (STATUSES as readonly string[]).includes(value);
}

// ---------------------------------------------------------------------------
// list — api/documents.php lines 212-279
// ---------------------------------------------------------------------------

interface DocumentListRow {
  id: number;
  doc_type: DocType;
  doc_number: string;
  issue_date: Date;
  due_date: Date | null;
  valid_until: Date | null;
  customer_user_id: number | null;
  customer_name: string | null;
  customer_tax_id: string | null;
  subtotal: string;
  discount_amount: string;
  vat_amount: string;
  total_amount: string;
  status: DocStatus;
  created_at: Date;
  approved_at: Date | null;
  cancelled_at: Date | null;
}

function pad2(n: number): string {
  return String(n).padStart(2, '0');
}
function toDateOnlyString(value: Date): string {
  return `${value.getFullYear()}-${pad2(value.getMonth() + 1)}-${pad2(value.getDate())}`;
}
function toDateTimeString(value: Date): string {
  return `${toDateOnlyString(value)} ${pad2(value.getHours())}:${pad2(value.getMinutes())}:${pad2(value.getSeconds())}`;
}

/** PHP's `(int)$v` cast on a raw query-string value (see api/inbox/messages/_lib/query.ts's `phpIntCast`, duplicated per this codebase's established convention). */
function phpIntCastQueryParam(value: string): number {
  const match = /^\s*[+-]?\d+/.exec(value);
  return match ? Number.parseInt(match[0], 10) : 0;
}

/** GET ?doc_type=&status=&q=&from=&to=&page=&per_page= — port of `case 'list':`. */
export async function GET(request: NextRequest): Promise<NextResponse> {
  const auth = await resolveDocumentsApiContext();
  if (!auth.ok) {
    return NextResponse.json({ success: false, error: 'Unauthorized' }, { status: auth.status });
  }
  const { db, session } = auth.value;
  // api/documents.php line 56's `$_SESSION['current_bot_id'] ?? ...` chain —
  // see _lib/session.ts's module doc for why only this fallback is ported.
  const lineAccountId = session.currentBotId ?? 1;

  const sp = new URL(request.url).searchParams;
  const docTypeRaw = (sp.get('doc_type') ?? '').trim().toUpperCase();
  const statusRaw = (sp.get('status') ?? '').trim();
  const q = (sp.get('q') ?? '').trim();
  const from = (sp.get('from') ?? '').trim();
  const to = (sp.get('to') ?? '').trim();
  const page = Math.max(1, phpIntCastQueryParam(sp.get('page') ?? '1'));
  const perPage = Math.min(200, Math.max(10, phpIntCastQueryParam(sp.get('per_page') ?? '50')));
  const offset = (page - 1) * perPage;

  // Built as `sql` fragments (not the typed query builder) so `issue_date`
  // range filters can bind plain `YYYY-MM-DD` strings directly — same
  // rationale as _lib/documents.ts's documentsInsert() doc comment
  // (business_documents' DATE columns are `Date`-typed on Kysely's
  // Insertable/where-operand side; PHP never constructs a DateTime for
  // these either, it just binds the raw string and lets MySQL parse it).
  const conditions: RawBuilder<unknown>[] = [sql`line_account_id = ${lineAccountId}`];
  if (docTypeRaw !== '' && isDocType(docTypeRaw)) {
    conditions.push(sql`doc_type = ${docTypeRaw}`);
  }
  if (isKnownStatus(statusRaw)) {
    conditions.push(sql`status = ${statusRaw}`);
  }
  if (q !== '') {
    const like = `%${q}%`;
    conditions.push(sql`(doc_number LIKE ${like} OR customer_name LIKE ${like} OR customer_tax_id LIKE ${like})`);
  }
  if (from !== '') conditions.push(sql`issue_date >= ${from}`);
  if (to !== '') conditions.push(sql`issue_date <= ${to}`);
  const whereSql = sql.join(conditions, sql` AND `);

  try {
    const countResult = await sql<{ total: number }>`SELECT COUNT(*) as total FROM business_documents WHERE ${whereSql}`.execute(db);
    const total = Number(countResult.rows[0]?.total ?? 0);

    const listResult = await sql<DocumentListRow>`
      SELECT id, doc_type, doc_number, issue_date, due_date, valid_until,
             customer_user_id, customer_name, customer_tax_id,
             subtotal, discount_amount, vat_amount, total_amount,
             status, created_at, approved_at, cancelled_at
        FROM business_documents
       WHERE ${whereSql}
       ORDER BY issue_date DESC, id DESC
       LIMIT ${perPage} OFFSET ${offset}
    `.execute(db);

    const data = listResult.rows.map((r) => ({
      ...r,
      issue_date: toDateOnlyString(r.issue_date),
      due_date: r.due_date ? toDateOnlyString(r.due_date) : null,
      valid_until: r.valid_until ? toDateOnlyString(r.valid_until) : null,
      created_at: toDateTimeString(r.created_at),
      approved_at: r.approved_at ? toDateTimeString(r.approved_at) : null,
      cancelled_at: r.cancelled_at ? toDateTimeString(r.cancelled_at) : null,
      doc_type_label: docTypeLabel(r.doc_type),
      status_label: docStatusLabel(r.status),
      issue_date_thai: formatThaiDate(toDateOnlyString(r.issue_date)),
    }));

    return NextResponse.json({
      success: true,
      data,
      pagination: {
        page,
        per_page: perPage,
        total,
        pages: Math.ceil(total / perPage),
      },
    });
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    return NextResponse.json({ success: false, error: `Failed to list documents: ${message}` }, { status: 400 });
  }
}

// ---------------------------------------------------------------------------
// create — api/documents.php lines 297-377
// ---------------------------------------------------------------------------

/** Bangkok (UTC+7, no DST) "today" as `YYYY-MM-DD`, immune to server-local timezone — same technique as `@reya/core`'s genDocNumber.ts `currentBangkokYearMonth()`. */
function bangkokTodayYmd(): string {
  const bangkok = new Date(Date.now() + 7 * 60 * 60 * 1000);
  return `${bangkok.getUTCFullYear()}-${pad2(bangkok.getUTCMonth() + 1)}-${pad2(bangkok.getUTCDate())}`;
}

/** POST {doc_type, vat_rate?, vat_inclusive?, items:[...], issue_date?, ...} — port of `case 'create':`. */
export async function POST(request: NextRequest): Promise<NextResponse> {
  const auth = await resolveDocumentsApiContext();
  if (!auth.ok) {
    return NextResponse.json({ success: false, error: 'Unauthorized' }, { status: auth.status });
  }
  const { db, session } = auth.value;
  const lineAccountId = session.currentBotId ?? 1;

  let raw: unknown;
  try {
    raw = await request.json();
  } catch {
    raw = {};
  }
  const body = raw !== null && typeof raw === 'object' ? (raw as Record<string, unknown>) : {};

  const docType = phpStringCast(phpIsset(body.doc_type) ? body.doc_type : '').trim().toUpperCase();
  if (!isDocType(docType)) {
    return NextResponse.json({ success: false, error: 'bad_doc_type' });
  }

  const vatRate = phpIsset(body.vat_rate) ? phpFloatCast(body.vat_rate) : 7.0;
  const vatInclusive = !phpEmpty(body.vat_inclusive);

  const rawItems = Array.isArray(body.items) ? body.items : [];
  if (rawItems.length === 0) {
    return NextResponse.json({ success: false, error: 'items_required' });
  }
  const norm = documentsNormItems(rawItems, vatRate, vatInclusive);
  if (norm.items.length === 0) {
    return NextResponse.json({ success: false, error: 'no_valid_items' });
  }

  let issueDate = phpStringCast(phpIsset(body.issue_date) ? body.issue_date : '').trim();
  if (issueDate === '') {
    issueDate = bangkokTodayYmd();
  }

  try {
    const docId = await db.transaction().execute(async (trx) => {
      const docNumber = await genDocNumber(trx, lineAccountId, docType);

      const doc: DocumentInsertInput = {
        line_account_id: lineAccountId,
        doc_type: docType,
        doc_number: docNumber,
        ref_transaction_id: phpIsset(body.ref_transaction_id) && phpIntCast(body.ref_transaction_id) > 0 ? phpIntCast(body.ref_transaction_id) : null,
        ref_doc_id: phpIsset(body.ref_doc_id) && phpIntCast(body.ref_doc_id) > 0 ? phpIntCast(body.ref_doc_id) : null,
        customer_user_id: phpIsset(body.customer_user_id) && phpIntCast(body.customer_user_id) > 0 ? phpIntCast(body.customer_user_id) : null,
        customer_name: phpIsset(body.customer_name) ? phpByteSubstr(phpStringCast(body.customer_name), 255) : null,
        customer_tax_id: phpIsset(body.customer_tax_id) ? phpByteSubstr(phpStringCast(body.customer_tax_id), 20) : null,
        customer_branch_code: phpIsset(body.customer_branch_code) ? phpByteSubstr(phpStringCast(body.customer_branch_code), 20) : null,
        customer_address: phpIsset(body.customer_address) ? phpStringCast(body.customer_address) : null,
        customer_phone: phpIsset(body.customer_phone) ? phpByteSubstr(phpStringCast(body.customer_phone), 50) : null,
        customer_email: phpIsset(body.customer_email) ? phpByteSubstr(phpStringCast(body.customer_email), 100) : null,
        issue_date: issueDate,
        due_date: !phpEmpty(body.due_date) ? phpStringCast(body.due_date) : null,
        valid_until: !phpEmpty(body.valid_until) ? phpStringCast(body.valid_until) : null,
        subtotal: norm.subtotal,
        discount_amount: norm.discount_amount,
        vat_rate: vatRate,
        vat_amount: norm.vat_amount,
        total_amount: norm.total_amount,
        payment_method: phpIsset(body.payment_method) ? phpByteSubstr(phpStringCast(body.payment_method), 50) : null,
        payment_ref: phpIsset(body.payment_ref) ? phpByteSubstr(phpStringCast(body.payment_ref), 100) : null,
        status: 'pending_approval',
        note: phpIsset(body.note) ? phpStringCast(body.note) : null,
        internal_note: phpIsset(body.internal_note) ? phpStringCast(body.internal_note) : null,
        created_by: session.adminUserId,
      };

      return documentsInsert(trx, doc, norm.items);
    });

    const full = await documentsFetch(db, lineAccountId, docId);
    const docNumberForLog = full?.doc_number ?? '';

    // Activity log — best-effort, outside the transaction, matching PHP's
    // $logger->logData() call AFTER $db->commit() (line 361). ActivityLogger::log()
    // swallows its own errors (classes/ActivityLogger.php's try/catch ->
    // returns null on failure) and never affects the response — mirrored
    // here with its own try/catch. Only log_type/action/description/
    // entity_type/entity_id/new_value/admin_id/admin_name/line_account_id
    // are written (no ip_address/user_agent/request_url/session_id/old_value)
    // — same subset precedent api/inbox/actions/notes/route.ts's activity_logs
    // insert already established.
    try {
      await sql`
        INSERT INTO activity_logs (log_type, action, description, entity_type, entity_id, new_value, admin_id, admin_name, line_account_id)
        VALUES ('data', 'create', ${`สร้างเอกสาร ${docType} ${docNumberForLog}`}, 'business_document', ${docId},
                ${JSON.stringify({ doc_number: docNumberForLog, total: norm.total_amount })}, ${session.adminUserId}, ${session.username}, ${lineAccountId})
      `.execute(db);
    } catch {
      // swallow — matches ActivityLogger::log()'s own catch.
    }

    return NextResponse.json({ success: true, data: full });
  } catch (error) {
    // matches PHP's error_log('[documents.create] ' . $e->getMessage())
    // being non-fatal / not part of the response contract.
    void error;
    return NextResponse.json(
      { success: false, error: 'create_failed', message: 'สร้างเอกสารไม่สำเร็จ' },
      { status: 500 }
    );
  }
}
