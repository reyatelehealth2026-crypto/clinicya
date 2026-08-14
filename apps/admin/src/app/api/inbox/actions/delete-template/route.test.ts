/**
 * @jest-environment node
 */
import type { NextRequest } from 'next/server';
import type { TenantSession } from '@reya/auth';
import { makeFakeTenantDb, type RecordedQuery } from './_lib/testHelpers/fakeTenantDb';

const mockResolveInboxApiContext = jest.fn();
jest.mock('./_lib/session', () => ({
  resolveInboxApiContext: () => mockResolveInboxApiContext(),
}));

// Only `loadTemplateService` is overridden per-test; `deleteTemplate` (and
// every other export) stays the REAL implementation via `jest.requireActual`,
// so ordinary tests still exercise the real DB-hitting code path against the
// fake Kysely db below. `beforeEach` (below) wires the default passthrough
// behavior — only the dedicated 503 test overrides it to return `null`,
// exactly the "explicit unit-test mock" the module doc calls for.
const mockLoadTemplateService = jest.fn();
jest.mock('./_lib/deleteTemplate', () => {
  const actual = jest.requireActual('./_lib/deleteTemplate') as typeof import('./_lib/deleteTemplate');
  return {
    ...actual,
    loadTemplateService: (...args: Parameters<typeof actual.loadTemplateService>) => mockLoadTemplateService(...args),
  };
});

import { GET, POST } from './route';

function req(body: unknown): NextRequest {
  return { json: async () => body } as unknown as NextRequest;
}

function fakeSession(overrides: Partial<TenantSession> = {}): TenantSession {
  return {
    realm: 'tenant',
    sid: 'sid',
    adminUserId: 42,
    tenantId: 1,
    currentBotId: 7,
    role: 'admin',
    username: 'admin',
    displayName: 'Admin',
    createdAt: new Date().toISOString(),
    lastSeenAt: new Date().toISOString(),
    expiresAt: new Date().toISOString(),
    ...overrides,
  };
}

const EXISTING_TEMPLATE_ROW = {
  id: 10,
  line_account_id: 7,
  name: 'Old name',
  content: 'Old content',
  category: 'general',
  quick_reply: null,
  usage_count: 0,
  last_used_at: null,
  created_by: 1,
  created_at: new Date(),
  updated_at: new Date(),
};

/**
 * Default `queryImpl`: the SELECT (getById) sees the row above, and any
 * subsequent DELETE sees an OkPacket. Override per test as needed.
 */
function wireFakeDb(
  queryImpl: (sqlText: string, params: unknown[]) => unknown = (sqlText) =>
    sqlText.toLowerCase().includes('select') ? [EXISTING_TEMPLATE_ROW] : { insertId: 0, affectedRows: 1 },
  sessionOverrides: Partial<TenantSession> = {}
): RecordedQuery[] {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession(sessionOverrides) } });
  return queries;
}

beforeEach(() => {
  jest.clearAllMocks();
  const actual = jest.requireActual('./_lib/deleteTemplate') as typeof import('./_lib/deleteTemplate');
  mockLoadTemplateService.mockImplementation(actual.loadTemplateService);
});

describe('POST /api/inbox/actions/delete-template', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });
    const res = await POST(req({ id: 10 }));
    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
    expect(mockLoadTemplateService).not.toHaveBeenCalled();
  });

  it('GET is method-not-allowed (405), matching sendError(\'Method not allowed\', 405)', async () => {
    const res = await GET();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });

  it.each([[{}], [{ id: 0 }], [{ id: null }]])(
    '400 {success:false, error:"Template ID is required"} for body=%j, no DB queries issued',
    async (body) => {
      const queries = wireFakeDb();
      const res = await POST(req(body));
      expect(res.status).toBe(400);
      expect(await res.json()).toEqual({ success: false, error: 'Template ID is required' });
      expect(queries).toHaveLength(0);
    }
  );

  it('503 {success:false, error:"Template service not available"} when loadTemplateService() returns null (explicit unit-test mock only, never reachable on real traffic)', async () => {
    const queries = wireFakeDb();
    mockLoadTemplateService.mockReturnValueOnce(null);

    const res = await POST(req({ id: 10 }));
    expect(res.status).toBe(503);
    expect(await res.json()).toEqual({ success: false, error: 'Template service not available' });
    expect(queries).toHaveLength(0);
  });

  it('not-found (getById scoped to line_account_id finds no row) -> 400 (NOT 404) {success:false, error:"Failed to delete template"}, no DELETE issued', async () => {
    const queries = wireFakeDb(() => []); // SELECT returns no rows for every query
    const res = await POST(req({ id: 999 }));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Failed to delete template' });
    // Only the getById SELECT ran — no DELETE was ever issued.
    expect(queries).toHaveLength(1);
    expect(queries[0]!.sql.toLowerCase()).toContain('select');
  });

  it('happy path: exact response envelope, DELETE FROM quick_reply_templates WHERE id = ? AND line_account_id = ?', async () => {
    const queries = wireFakeDb();
    const res = await POST(req({ id: 10 }));
    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({ success: true, message: 'Template deleted successfully' });

    expect(queries).toHaveLength(2);
    expect(queries[0]!.sql.toLowerCase()).toContain('select');
    const deleteQuery = queries[1]!;
    expect(deleteQuery.sql.toLowerCase()).toContain('delete from');
    expect(deleteQuery.sql.toLowerCase()).toContain('quick_reply_templates');
    expect(deleteQuery.params).toEqual([10, 7]);
  });

  it('id as a numeric string ("10") is coerced via (int) cast the same as a real number', async () => {
    const queries = wireFakeDb();
    const res = await POST(req({ id: '10' }));
    expect(res.status).toBe(200);
    const deleteQuery = queries[1]!;
    expect(deleteQuery.params).toEqual([10, 7]);
  });

  it('DB failure -> 400 {success:false, error: "Failed to delete template: ..."}', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });

    const res = await POST(req({ id: 10 }));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Failed to delete template: boom' });
  });
});
