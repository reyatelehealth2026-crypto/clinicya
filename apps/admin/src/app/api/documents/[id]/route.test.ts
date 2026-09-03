/**
 * @jest-environment node
 */
import type { TenantSession } from '@reya/auth';
import { makeFakeTenantDb, type RecordedQuery } from '../_lib/testHelpers/fakeTenantDb';

const mockResolveDocumentsApiContext = jest.fn();
jest.mock('../_lib/session', () => ({
  resolveDocumentsApiContext: () => mockResolveDocumentsApiContext(),
}));

import { GET } from './route';

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

function params(id: string): { params: Promise<{ id: string }> } {
  return { params: Promise.resolve({ id }) };
}

function fullDocRow(overrides: Record<string, unknown> = {}) {
  return {
    id: 5,
    line_account_id: 7,
    doc_type: 'TAX',
    doc_number: 'TAX-6905-0003',
    ref_transaction_id: null,
    ref_doc_id: null,
    customer_user_id: null,
    customer_name: 'บริษัท ทดสอบ จำกัด',
    customer_tax_id: '0123456789012',
    customer_branch_code: '00000',
    customer_address: null,
    customer_phone: null,
    customer_email: null,
    issue_date: new Date(2026, 4, 24),
    due_date: null,
    valid_until: null,
    subtotal: '1000.00',
    discount_amount: '0.00',
    vat_rate: '7.00',
    vat_amount: '70.00',
    total_amount: '1070.00',
    payment_method: null,
    payment_ref: null,
    status: 'approved',
    note: null,
    internal_note: null,
    created_by: 42,
    approved_by: 42,
    approved_at: new Date(2026, 4, 24, 10, 0, 0),
    cancelled_by: null,
    cancelled_at: null,
    cancel_reason: null,
    pdf_path: null,
    created_at: new Date(2026, 4, 24, 9, 0, 0),
    updated_at: new Date(2026, 4, 24, 10, 0, 0),
    ...overrides,
  };
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('GET /api/documents/[id] — get (case documents.php lines 282-294)', () => {
  it('401 JSON when unauthenticated', async () => {
    mockResolveDocumentsApiContext.mockResolvedValue({ ok: false, status: 401 });
    const res = await GET(new Request('https://x/api/documents/5'), params('5'));
    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
  });

  it('bad_id for id<=0 or non-numeric, DB never touched', async () => {
    const queries = wireFakeDb(() => {
      throw new Error('should not query');
    });
    const res = await GET(new Request('https://x'), params('0'));
    expect(await res.json()).toEqual({ success: false, error: 'bad_id' });

    const res2 = await GET(new Request('https://x'), params('-3'));
    expect(await res2.json()).toEqual({ success: false, error: 'bad_id' });

    const res3 = await GET(new Request('https://x'), params('abc'));
    expect(await res3.json()).toEqual({ success: false, error: 'bad_id' });

    expect(queries).toHaveLength(0);
  });

  it('404 not_found when no row matches id + line_account_id', async () => {
    wireFakeDb(() => []);
    const res = await GET(new Request('https://x'), params('999'));
    expect(res.status).toBe(404);
    expect(await res.json()).toEqual({ success: false, error: 'not_found' });
  });

  it('scopes the lookup by line_account_id = session.currentBotId ?? 1', async () => {
    const queries = wireFakeDb((sqlText) => {
      if (/\bbusiness_documents\b/i.test(sqlText)) return [fullDocRow()];
      return [];
    });
    await GET(new Request('https://x'), params('5'));
    const docQuery = queries.find((q) => /\bbusiness_documents\b/i.test(q.sql) && !/business_document_items/i.test(q.sql));
    expect(docQuery!.params).toEqual([5, 7]);
  });

  it('200: full row + items + doc_type_label/status_label/issue_date_thai, dates as PHP-style strings', async () => {
    wireFakeDb((sqlText) => {
      if (/\bbusiness_document_items\b/i.test(sqlText)) {
        return [{ id: 1, document_id: 5, line_no: 1, product_name: 'ยา A', quantity: '1.00', unit_price: '1000.00', line_total: '1000.00' }];
      }
      if (/\bbusiness_documents\b/i.test(sqlText)) return [fullDocRow()];
      return [];
    });
    const res = await GET(new Request('https://x'), params('5'));
    expect(res.status).toBe(200);
    const body = await res.json();
    expect(body.success).toBe(true);
    expect(body.data.id).toBe(5);
    expect(body.data.items).toHaveLength(1);
    expect(body.data.doc_type_label).toBe('ใบกำกับภาษี');
    expect(body.data.status_label).toBe('อนุมัติ');
    expect(body.data.issue_date_thai).toBe('24 พ.ค. 2569');
    expect(body.data.issue_date).toBe('2026-05-24');
    expect(body.data.approved_at).toBe('2026-05-24 10:00:00');
    expect(body.data.cancelled_at).toBeNull();
  });
});
