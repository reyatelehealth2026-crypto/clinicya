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

// Only `loadTemplateService` is overridden per-test; `updateTemplate` (and
// every other export) stays the REAL implementation via `jest.requireActual`,
// so ordinary tests still exercise the real DB-hitting code path against the
// fake Kysely db below. `beforeEach` (below) wires the default passthrough
// behavior — only the dedicated 503 test overrides it to return `null`,
// exactly the "explicit unit-test mock" the module doc calls for.
const mockLoadTemplateService = jest.fn();
jest.mock('./_lib/updateTemplate', () => {
  const actual = jest.requireActual('./_lib/updateTemplate') as typeof import('./_lib/updateTemplate');
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
 * subsequent UPDATE sees an OkPacket. Override per test as needed.
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
  const actual = jest.requireActual('./_lib/updateTemplate') as typeof import('./_lib/updateTemplate');
  mockLoadTemplateService.mockImplementation(actual.loadTemplateService);
});

describe('POST /api/inbox/actions/update-template', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });
    const res = await POST(req({ id: 10, name: 'New name' }));
    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
    expect(mockLoadTemplateService).not.toHaveBeenCalled();
  });

  it('GET is method-not-allowed (405), matching sendError(\'Method not allowed\', 405)', async () => {
    const res = await GET();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });

  it.each([[{ name: 'x' }], [{ id: 0, name: 'x' }], [{}]])(
    '400 {success:false, error:"Template ID is required"} for body=%j, no DB queries issued',
    async (body) => {
      const queries = wireFakeDb();
      const res = await POST(req(body));
      expect(res.status).toBe(400);
      expect(await res.json()).toEqual({ success: false, error: 'Template ID is required' });
      expect(queries).toHaveLength(0);
    }
  );

  it('400 {success:false, error:"No data to update"} when id present but no other field, no DB queries issued', async () => {
    const queries = wireFakeDb();
    const res = await POST(req({ id: 10 }));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'No data to update' });
    expect(queries).toHaveLength(0);
  });

  it('400 "No data to update" when every field is explicitly null (isset()-false for all of them)', async () => {
    const queries = wireFakeDb();
    const res = await POST(req({ id: 10, name: null, content: null, category: null, quick_reply: null }));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'No data to update' });
    expect(queries).toHaveLength(0);
  });

  it('503 {success:false, error:"Template service not available"} when loadTemplateService() returns null (explicit unit-test mock only, never reachable on real traffic)', async () => {
    const queries = wireFakeDb();
    mockLoadTemplateService.mockReturnValueOnce(null);

    const res = await POST(req({ id: 10, name: 'New name' }));
    expect(res.status).toBe(503);
    expect(await res.json()).toEqual({ success: false, error: 'Template service not available' });
    expect(queries).toHaveLength(0);
  });

  it('not-found (getById scoped to line_account_id finds no row) -> 400 (NOT 404) {success:false, error:"Failed to update template"}', async () => {
    const queries = wireFakeDb(() => []); // SELECT returns no rows for every query
    const res = await POST(req({ id: 999, name: 'New name' }));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Failed to update template' });
    // Only the getById SELECT ran — no UPDATE was ever issued.
    expect(queries).toHaveLength(1);
    expect(queries[0]!.sql.toLowerCase()).toContain('select');
  });

  it('happy path: name+content+category, exact response envelope, UPDATE ... WHERE id = ? AND line_account_id = ?', async () => {
    const queries = wireFakeDb();
    const res = await POST(req({ id: 10, name: '  New name  ', content: '  New content  ', category: '  promo  ' }));
    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({ success: true, message: 'Template updated successfully' });

    expect(queries).toHaveLength(2);
    const updateQuery = queries[1]!;
    expect(updateQuery.sql.toLowerCase()).toContain('update');
    expect(updateQuery.sql.toLowerCase()).toContain('quick_reply_templates');
    expect(updateQuery.sql.toLowerCase()).toContain('where');
    // name/content are trimmed before binding; category is trimmed unconditionally too.
    expect(updateQuery.params).toEqual(['New name', 'New content', 'promo', 10, 7]);
  });

  it('name all-whitespace after trim() -> throws -> 400 "Failed to update template: Template name cannot be empty" (no UPDATE issued)', async () => {
    const queries = wireFakeDb();
    const res = await POST(req({ id: 10, name: '   ' }));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Failed to update template: Template name cannot be empty' });
    // Only the getById SELECT ran.
    expect(queries).toHaveLength(1);
  });

  it('name trims to the literal string "0" -> PHP empty()-string-zero edge case -> throws "Template name cannot be empty"', async () => {
    wireFakeDb();
    const res = await POST(req({ id: 10, name: '0' }));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Failed to update template: Template name cannot be empty' });
  });

  it('content all-whitespace after trim() -> "Failed to update template: Template content cannot be empty"', async () => {
    wireFakeDb();
    const res = await POST(req({ id: 10, content: '   ' }));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Failed to update template: Template content cannot be empty' });
  });

  it('category alone, trimmed unconditionally with no emptiness check — an empty category is a valid "clear it" update', async () => {
    const queries = wireFakeDb();
    const res = await POST(req({ id: 10, category: '   ' }));
    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({ success: true, message: 'Template updated successfully' });

    const updateQuery = queries[1]!;
    expect(updateQuery.params).toEqual(['', 10, 7]);
  });

  describe('quick_reply: isset()-vs-null gotcha', () => {
    it('quick_reply: "" (exact empty string) IS isset() -> included in payload, coerced to null -> UPDATE clears the column', async () => {
      const queries = wireFakeDb();
      const res = await POST(req({ id: 10, quick_reply: '' }));
      expect(res.status).toBe(200);
      expect(await res.json()).toEqual({ success: true, message: 'Template updated successfully' });

      expect(queries).toHaveLength(2);
      const updateQuery = queries[1]!;
      expect(updateQuery.sql.toLowerCase()).toContain('quick_reply');
      expect(updateQuery.params).toEqual([null, 10, 7]);
    });

    it('quick_reply: null in the body is NOT isset() -> treated as absent -> "No data to update" when it is the only field, column left untouched', async () => {
      const queries = wireFakeDb();
      const res = await POST(req({ id: 10, quick_reply: null }));
      expect(res.status).toBe(400);
      expect(await res.json()).toEqual({ success: false, error: 'No data to update' });
      // No SELECT/UPDATE at all — the payload was empty before the service was even loaded.
      expect(queries).toHaveLength(0);
    });

    it('quick_reply: null alongside a real field (e.g. name) -> quick_reply key dropped entirely, column untouched, only name is in the SET clause', async () => {
      const queries = wireFakeDb();
      const res = await POST(req({ id: 10, name: 'New name', quick_reply: null }));
      expect(res.status).toBe(200);

      const updateQuery = queries[1]!;
      // The table name itself contains "quick_reply" (`quick_reply_templates`) — assert on the
      // SET-clause column reference specifically, not a bare substring match against the whole SQL text.
      expect(updateQuery.sql.toLowerCase()).not.toMatch(/set[^w]*`?quick_reply`?\s*=/);
      expect(updateQuery.params).toEqual(['New name', 10, 7]);
    });

    it('quick_reply: a real string value passes through raw (already null-coerced upstream only for the "" case)', async () => {
      const queries = wireFakeDb();
      const res = await POST(req({ id: 10, quick_reply: '[{"type":"action"}]' }));
      expect(res.status).toBe(200);

      const updateQuery = queries[1]!;
      expect(updateQuery.params).toEqual(['[{"type":"action"}]', 10, 7]);
    });
  });

  it('DB failure -> 400 {success:false, error: "Failed to update template: ..."}', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });

    const res = await POST(req({ id: 10, name: 'x' }));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Failed to update template: boom' });
  });
});
