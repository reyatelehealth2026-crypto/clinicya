/**
 * @jest-environment node
 */
import type { NextRequest } from 'next/server';
import type { TenantSession } from '@reya/auth';
import { REYA_DOCUMENT_TYPES } from '@reya/core';
import { makeFakeTenantDb, type RecordedQuery } from './_lib/testHelpers/fakeTenantDb';

const mockResolveDocumentsApiContext = jest.fn();
jest.mock('./_lib/session', () => ({
  resolveDocumentsApiContext: () => mockResolveDocumentsApiContext(),
}));

import { GET, POST } from './route';

function getReq(search: string): NextRequest {
  return { url: `https://admin.re-ya.com/api/documents${search}` } as unknown as NextRequest;
}

function postReq(body: unknown): NextRequest {
  return { url: 'https://admin.re-ya.com/api/documents', json: async () => body } as unknown as NextRequest;
}

function fakeSession(overrides: Partial<TenantSession> = {}): TenantSession {
  return {
    realm: 'tenant',
    sid: 'sid',
    adminUserId: 42,
    tenantId: 1,
    currentBotId: 7,
    role: 'admin',
    username: 'pharmacist_a',
    displayName: 'Admin',
    createdAt: new Date().toISOString(),
    lastSeenAt: new Date().toISOString(),
    expiresAt: new Date().toISOString(),
    ...overrides,
  };
}

function wireFakeDb(
  queryImpl: (sqlText: string, params: unknown[]) => unknown = () => [],
  sessionOverrides: Partial<TenantSession> = {}
): RecordedQuery[] {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveDocumentsApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession(sessionOverrides) } });
  return queries;
}

beforeEach(() => {
  jest.clearAllMocks();
});

function fullDocRow(overrides: Record<string, unknown> = {}) {
  return {
    id: 501,
    line_account_id: 7,
    doc_type: 'QT',
    doc_number: 'QT-6905-0001',
    ref_transaction_id: null,
    ref_doc_id: null,
    customer_user_id: null,
    customer_name: 'ทดสอบ',
    customer_tax_id: null,
    customer_branch_code: null,
    customer_address: null,
    customer_phone: null,
    customer_email: null,
    issue_date: new Date(2026, 4, 24),
    due_date: null,
    valid_until: null,
    subtotal: '100.00',
    discount_amount: '0.00',
    vat_rate: '7.00',
    vat_amount: '7.00',
    total_amount: '107.00',
    payment_method: null,
    payment_ref: null,
    status: 'pending_approval',
    note: null,
    internal_note: null,
    created_by: 42,
    approved_by: null,
    approved_at: null,
    cancelled_by: null,
    cancelled_at: null,
    cancel_reason: null,
    pdf_path: null,
    created_at: new Date(2026, 4, 24, 9, 0, 0),
    updated_at: new Date(2026, 4, 24, 9, 0, 0),
    ...overrides,
  };
}

/** Happy-path responder for the whole create flow: genDocNumber + business_documents/items insert + post-commit documentsFetch + activity log. */
function makeCreateHappyPathImpl(opts: { lastSeq?: number; insertedDocId?: number; docRow?: Record<string, unknown> } = {}) {
  const lastSeq = opts.lastSeq ?? 0;
  const insertedDocId = opts.insertedDocId ?? 501;
  const docRow = opts.docRow ?? fullDocRow({ id: insertedDocId });
  return (sqlText: string): unknown => {
    const s = sqlText.trim();
    const lower = s.toLowerCase();
    if (/^(begin|commit|rollback)/i.test(s)) return {};
    if (/^insert\s+ignore/i.test(s)) return { affectedRows: 1 };
    if (/^select/i.test(s) && lower.includes('document_sequences')) return [{ id: 1, last_seq: lastSeq }];
    if (/^update/i.test(s) && lower.includes('document_sequences')) return { affectedRows: 1 };
    if (/^insert into/i.test(s) && /\bbusiness_documents\b/i.test(s)) return { insertId: insertedDocId, affectedRows: 1 };
    if (/^insert into/i.test(s) && /\bbusiness_document_items\b/i.test(s)) return { affectedRows: 1 };
    if (/^select/i.test(s) && /\bbusiness_documents\b/i.test(s)) return [docRow];
    if (/^select/i.test(s) && /\bbusiness_document_items\b/i.test(s)) return [];
    if (/^insert into/i.test(s) && /\bactivity_logs\b/i.test(s)) return { affectedRows: 1 };
    throw new Error(`unexpected query: ${s}`);
  };
}

describe('GET /api/documents — list (case documents.php lines 212-279)', () => {
  it('401 JSON when unauthenticated', async () => {
    mockResolveDocumentsApiContext.mockResolvedValue({ ok: false, status: 401 });
    const res = await GET(getReq(''));
    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('defaults: page=1, per_page=50, scoped to line_account_id (session.currentBotId ?? 1)', async () => {
    const queries = wireFakeDb((sqlText) => {
      if (/^select count/i.test(sqlText)) return [{ total: 0 }];
      return [];
    });
    const res = await GET(getReq(''));
    const body = await res.json();
    expect(body.pagination).toEqual({ page: 1, per_page: 50, total: 0, pages: 0 });

    const countQuery = queries.find((q) => /^select count/i.test(q.sql));
    expect(countQuery!.params).toEqual([7]); // session.currentBotId
  });

  it('per_page is clamped to [10, 200]', async () => {
    wireFakeDb((sqlText) => (/^select count/i.test(sqlText) ? [{ total: 0 }] : []));
    const low = await (await GET(getReq('?per_page=1'))).json();
    expect(low.pagination.per_page).toBe(10);
    const high = await (await GET(getReq('?per_page=9999'))).json();
    expect(high.pagination.per_page).toBe(200);
    const mid = await (await GET(getReq('?per_page=75'))).json();
    expect(mid.pagination.per_page).toBe(75);
  });

  it('page is floored at 1', async () => {
    wireFakeDb((sqlText) => (/^select count/i.test(sqlText) ? [{ total: 0 }] : []));
    const body = await (await GET(getReq('?page=0'))).json();
    expect(body.pagination.page).toBe(1);
    const body2 = await (await GET(getReq('?page=-5'))).json();
    expect(body2.pagination.page).toBe(1);
  });

  it('doc_type filter is applied only for a known doc_type; unknown doc_type is silently ignored (no filter, matches PHP)', async () => {
    const queries = wireFakeDb((sqlText) => (/^select count/i.test(sqlText) ? [{ total: 0 }] : []));
    await GET(getReq('?doc_type=inv'));
    let countQuery = queries.find((q) => /^select count/i.test(q.sql));
    expect(countQuery!.sql.toLowerCase()).toContain('doc_type');
    expect(countQuery!.params).toEqual([7, 'INV']);

    queries.length = 0;
    await GET(getReq('?doc_type=bogus'));
    countQuery = queries.find((q) => /^select count/i.test(q.sql));
    expect(countQuery!.sql.toLowerCase()).not.toContain('doc_type =');
    expect(countQuery!.params).toEqual([7]);
  });

  it('status filter only applies for one of the 3 known statuses', async () => {
    const queries = wireFakeDb((sqlText) => (/^select count/i.test(sqlText) ? [{ total: 0 }] : []));
    await GET(getReq('?status=approved'));
    const countQuery = queries.find((q) => /^select count/i.test(q.sql));
    expect(countQuery!.params).toEqual([7, 'approved']);

    queries.length = 0;
    await GET(getReq('?status=bogus_status'));
    const countQuery2 = queries.find((q) => /^select count/i.test(q.sql));
    expect(countQuery2!.params).toEqual([7]);
  });

  it('q applies a 3-column LIKE OR filter with %wrapping%', async () => {
    const queries = wireFakeDb((sqlText) => (/^select count/i.test(sqlText) ? [{ total: 0 }] : []));
    await GET(getReq('?q=ACME'));
    const countQuery = queries.find((q) => /^select count/i.test(q.sql));
    expect(countQuery!.sql.toLowerCase()).toContain('like');
    expect(countQuery!.params).toEqual([7, '%ACME%', '%ACME%', '%ACME%']);
  });

  it('from/to apply issue_date range filters', async () => {
    const queries = wireFakeDb((sqlText) => (/^select count/i.test(sqlText) ? [{ total: 0 }] : []));
    await GET(getReq('?from=2026-01-01&to=2026-12-31'));
    const countQuery = queries.find((q) => /^select count/i.test(q.sql));
    expect(countQuery!.params).toEqual([7, '2026-01-01', '2026-12-31']);
  });

  it('decorates each row with doc_type_label/status_label/issue_date_thai and serializes dates as PHP-style strings', async () => {
    wireFakeDb((sqlText) => {
      if (/^select count/i.test(sqlText)) return [{ total: 1 }];
      if (/select id, doc_type/i.test(sqlText)) {
        return [
          {
            id: 1,
            doc_type: 'INV',
            doc_number: 'INV-6905-0001',
            issue_date: new Date(2026, 4, 24),
            due_date: null,
            valid_until: null,
            customer_user_id: null,
            customer_name: 'X',
            customer_tax_id: null,
            subtotal: '100.00',
            discount_amount: '0.00',
            vat_amount: '7.00',
            total_amount: '107.00',
            status: 'approved',
            created_at: new Date(2026, 4, 24, 8, 0, 0),
            approved_at: new Date(2026, 4, 24, 9, 0, 0),
            cancelled_at: null,
          },
        ];
      }
      return [];
    });
    const body = await (await GET(getReq(''))).json();
    expect(body.data).toHaveLength(1);
    expect(body.data[0]).toMatchObject({
      doc_type_label: 'ใบแจ้งหนี้',
      status_label: 'อนุมัติ',
      issue_date_thai: '24 พ.ค. 2569',
      issue_date: '2026-05-24',
      created_at: '2026-05-24 08:00:00',
      approved_at: '2026-05-24 09:00:00',
    });
  });

  it('correctly labels all 11 REYA_DOCUMENT_TYPES when returned through the list row decoration', async () => {
    for (const [docType, meta] of Object.entries(REYA_DOCUMENT_TYPES)) {
      wireFakeDb((sqlText) => {
        if (/^select count/i.test(sqlText)) return [{ total: 1 }];
        if (/select id, doc_type/i.test(sqlText)) {
          return [
            {
              id: 1,
              doc_type: docType,
              doc_number: `${docType}-6905-0001`,
              issue_date: new Date(2026, 4, 24),
              due_date: null,
              valid_until: null,
              customer_user_id: null,
              customer_name: null,
              customer_tax_id: null,
              subtotal: '100.00',
              discount_amount: '0.00',
              vat_amount: '7.00',
              total_amount: '107.00',
              status: 'pending_approval',
              created_at: new Date(2026, 4, 24, 8, 0, 0),
              approved_at: null,
              cancelled_at: null,
            },
          ];
        }
        return [];
      });
      const body = await (await GET(getReq(`?doc_type=${docType}`))).json();
      expect(body.data[0].doc_type_label).toBe(meta.label);
      expect(body.data[0].status_label).toBe('รออนุมัติ');
    }
  });
});

describe('POST /api/documents — create (case documents.php lines 297-377)', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveDocumentsApiContext.mockResolvedValue({ ok: false, status: 401 });
    const res = await POST(postReq({ doc_type: 'QT', items: [{ product_name: 'X', quantity: 1, unit_price: 10 }] }));
    expect(res.status).toBe(401);
  });

  it('bad_doc_type for an unknown doc_type — no query issued at all', async () => {
    const queries = wireFakeDb(makeCreateHappyPathImpl());
    const res = await POST(postReq({ doc_type: 'XX', items: [{ product_name: 'X', quantity: 1, unit_price: 10 }] }));
    const body = await res.json();
    expect(body).toEqual({ success: false, error: 'bad_doc_type' });
    expect(queries).toHaveLength(0);
  });

  it('items_required when items is missing/empty', async () => {
    wireFakeDb(makeCreateHappyPathImpl());
    const res = await POST(postReq({ doc_type: 'QT', items: [] }));
    expect(await res.json()).toEqual({ success: false, error: 'items_required' });

    const res2 = await POST(postReq({ doc_type: 'QT' }));
    expect(await res2.json()).toEqual({ success: false, error: 'items_required' });
  });

  it('no_valid_items when every item is skipped (blank name / non-positive qty)', async () => {
    wireFakeDb(makeCreateHappyPathImpl());
    const res = await POST(
      postReq({ doc_type: 'QT', items: [{ product_name: '', quantity: 1, unit_price: 10 }, { product_name: 'Y', quantity: 0 }] })
    );
    expect(await res.json()).toEqual({ success: false, error: 'no_valid_items' });
  });

  it('successful create: atomic genDocNumber + insert, returns the full fetched document', async () => {
    const queries = wireFakeDb(makeCreateHappyPathImpl({ lastSeq: 5, insertedDocId: 501 }));
    const res = await POST(
      postReq({
        doc_type: 'inv',
        items: [{ product_name: 'ยา A', quantity: 2, unit_price: 50 }],
        customer_name: 'บริษัท เอบีซี',
      })
    );
    expect(res.status).toBe(200);
    const body = await res.json();
    expect(body.success).toBe(true);
    expect(body.data.id).toBe(501);

    const controlStatements = queries.filter((q) => /^(begin|commit|rollback)/i.test(q.sql.trim())).map((q) => q.sql.trim().toLowerCase());
    expect(controlStatements).toEqual(['begin', 'commit']);
  });

  it('all 11 REYA_DOCUMENT_TYPES are accepted', async () => {
    const types = ['QT', 'BL', 'INV', 'RE', 'TAX', 'DN', 'CN', 'PO', 'GR', 'DNP', 'CNP'];
    for (const t of types) {
      wireFakeDb(makeCreateHappyPathImpl({ lastSeq: 0, insertedDocId: 1, docRow: fullDocRow({ id: 1, doc_type: t, doc_number: `${t}-6905-0001` }) }));
      const res = await POST(postReq({ doc_type: t, items: [{ product_name: 'X', quantity: 1, unit_price: 10 }] }));
      const body = await res.json();
      expect(body.success).toBe(true);
    }
  });

  it('ATOMICITY: when the business_documents insert throws after genDocNumber already ran, the whole transaction rolls back — 500 create_failed, no commit', async () => {
    const queries = wireFakeDb((sqlText) => {
      const s = sqlText.trim();
      const lower = s.toLowerCase();
      if (/^(begin|commit|rollback)/i.test(s)) return {};
      if (/^insert\s+ignore/i.test(s)) return { affectedRows: 1 };
      if (/^select/i.test(s) && lower.includes('document_sequences')) return [{ id: 1, last_seq: 0 }];
      if (/^update/i.test(s) && lower.includes('document_sequences')) return { affectedRows: 1 };
      if (/^insert into/i.test(s) && /\bbusiness_documents\b/i.test(s)) throw new Error('simulated business_documents insert failure');
      throw new Error(`unexpected: ${s}`);
    });

    const res = await POST(postReq({ doc_type: 'QT', items: [{ product_name: 'X', quantity: 1, unit_price: 10 }] }));
    expect(res.status).toBe(500);
    const body = await res.json();
    expect(body).toEqual({ success: false, error: 'create_failed', message: 'สร้างเอกสารไม่สำเร็จ' });

    const controlStatements = queries.filter((q) => /^(begin|commit|rollback)/i.test(q.sql.trim())).map((q) => q.sql.trim().toLowerCase());
    // Exactly one begin/rollback pair — the sequence bump (UPDATE document_sequences)
    // ran inside this same never-committed transaction, so it never survives.
    expect(controlStatements).toEqual(['begin', 'rollback']);
    expect(controlStatements).not.toContain('commit');
    const updateSeq = queries.find((q) => /^update/i.test(q.sql.trim()) && q.sql.toLowerCase().includes('document_sequences'));
    expect(updateSeq).toBeDefined(); // the bump DID run...
    // ...but nothing after the throw (business_document_items insert, activity log) ever ran.
    expect(queries.some((q) => /business_document_items/i.test(q.sql))).toBe(false);
  });

  it('uses session.currentBotId ?? 1 as the lineAccountId (falls back to 1 when null)', async () => {
    const queries = wireFakeDb(makeCreateHappyPathImpl({ lastSeq: 0, insertedDocId: 1 }), { currentBotId: null });
    await POST(postReq({ doc_type: 'QT', items: [{ product_name: 'X', quantity: 1, unit_price: 10 }] }));
    const insertIgnore = queries.find((q) => /^insert\s+ignore/i.test(q.sql));
    expect(insertIgnore!.params[0]).toBe(1); // lineAccountId fallback
  });
});
