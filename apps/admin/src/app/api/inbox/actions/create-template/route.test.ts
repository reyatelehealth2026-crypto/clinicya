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

// Only `loadTemplateService` is overridden per-test; `createTemplate` (and
// every other export) stays the REAL implementation via `jest.requireActual`,
// so ordinary tests still exercise the real DB-hitting code path against the
// fake Kysely db below. `beforeEach` (below) wires the default passthrough
// behavior — only the dedicated 503 test overrides it to return `null`,
// exactly the "explicit unit-test mock" the module doc calls for.
const mockLoadTemplateService = jest.fn();
jest.mock('./_lib/createTemplate', () => {
  const actual = jest.requireActual('./_lib/createTemplate') as typeof import('./_lib/createTemplate');
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

function wireFakeDb(
  queryImpl: (sqlText: string, params: unknown[]) => unknown = () => ({ insertId: 501, affectedRows: 1 }),
  sessionOverrides: Partial<TenantSession> = {}
): RecordedQuery[] {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession(sessionOverrides) } });
  return queries;
}

beforeEach(() => {
  jest.clearAllMocks();
  // Default: route straight through to the REAL `loadTemplateService` (a real
  // handle whose `createTemplate` method hits the fake DB below) — set here,
  // not inside the `jest.mock` factory itself, so `mockLoadTemplateService`
  // is fully initialized before this runs (the factory is evaluated lazily
  // on first `require('./_lib/createTemplate')`, which — via Babel's import
  // hoisting for `import { GET, POST } from './route'` below — happens
  // before this file's own top-level `const mockLoadTemplateService = jest.fn();`
  // would otherwise be read). Only the dedicated 503 test overrides this
  // per-test via `mockReturnValueOnce(null)`.
  const actual = jest.requireActual('./_lib/createTemplate') as typeof import('./_lib/createTemplate');
  mockLoadTemplateService.mockImplementation(actual.loadTemplateService);
});

describe('POST /api/inbox/actions/create-template', () => {
  it('401 JSON when unauthenticated, DB never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });
    const res = await POST(req({ name: 'Greeting', content: 'สวัสดีค่ะ' }));
    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
    expect(mockLoadTemplateService).not.toHaveBeenCalled();
  });

  it('GET is method-not-allowed (405), matching sendError(\'Method not allowed\', 405)', async () => {
    const res = await GET();
    expect(res.status).toBe(405);
    expect(await res.json()).toEqual({ success: false, error: 'Method not allowed' });
  });

  it.each([
    [{ content: 'hi' }], // missing name
    [{ name: '', content: 'hi' }], // empty-string name
    [{ name: '0', content: 'hi' }], // PHP empty() string-'0' edge case
    [{ name: 'ok' }], // missing content
    [{ name: 'ok', content: '' }], // empty-string content
    [{ name: 'ok', content: '0' }], // PHP empty() string-'0' edge case, content side
    [{}],
  ])(
    '400 {success:false, error:"Name and content are required"} for body=%j, no DB queries issued',
    async (body) => {
      const queries = wireFakeDb();
      const res = await POST(req(body));
      expect(res.status).toBe(400);
      expect(await res.json()).toEqual({ success: false, error: 'Name and content are required' });
      expect(queries).toHaveLength(0);
    }
  );

  it('503 {success:false, error:"Template service not available"} when loadTemplateService() returns null (explicit unit-test mock only, never reachable on real traffic)', async () => {
    const queries = wireFakeDb();
    mockLoadTemplateService.mockReturnValueOnce(null);

    const res = await POST(req({ name: 'Greeting', content: 'สวัสดีค่ะ' }));
    expect(res.status).toBe(503);
    expect(await res.json()).toEqual({ success: false, error: 'Template service not available' });
    expect(queries).toHaveLength(0);
  });

  it('happy path: INSERT INTO quick_reply_templates, created_by = session.adminUserId, exact response envelope', async () => {
    const queries = wireFakeDb(() => ({ insertId: 501, affectedRows: 1 }));

    const res = await POST(req({ name: 'Greeting', content: 'สวัสดีค่ะ', category: 'general', quick_reply: '[{"type":"action"}]' }));
    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({ success: true, message: 'Template created successfully', id: 501 });

    expect(queries).toHaveLength(1);
    const insertQuery = queries[0]!;
    expect(insertQuery.sql.toLowerCase()).toContain('insert into');
    expect(insertQuery.sql.toLowerCase()).toContain('quick_reply_templates');
    expect(insertQuery.params).toEqual([7, 'Greeting', 'สวัสดีค่ะ', 'general', 42, '[{"type":"action"}]']);
  });

  it('quick_reply omitted from body -> null bound (PHP `?? null` default)', async () => {
    const queries = wireFakeDb(() => ({ insertId: 502, affectedRows: 1 }));
    await POST(req({ name: 'Greeting', content: 'hi' }));

    const insertQuery = queries[0]!;
    expect(insertQuery.params).toEqual([7, 'Greeting', 'hi', '', 42, null]);
  });

  it('quick_reply === "" (exact empty string) coerced to null before the service call — PHP `if ($quickReply === \'\') $quickReply = null;`', async () => {
    const queries = wireFakeDb(() => ({ insertId: 503, affectedRows: 1 }));
    await POST(req({ name: 'Greeting', content: 'hi', quick_reply: '' }));

    const insertQuery = queries[0]!;
    expect(insertQuery.params).toEqual([7, 'Greeting', 'hi', '', 42, null]);
  });

  it('name all-whitespace after trim() -> service throws InvalidArgumentException equivalent -> 400 "Failed to create template: Template name is required"', async () => {
    const queries = wireFakeDb();
    const res = await POST(req({ name: '   ', content: 'hi' }));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Failed to create template: Template name is required' });
    // The service's own INSERT never runs once trim()+empty() throws.
    expect(queries).toHaveLength(0);
  });

  it('content all-whitespace after trim() -> 400 "Failed to create template: Template content is required"', async () => {
    wireFakeDb();
    const res = await POST(req({ name: 'ok', content: '   ' }));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Failed to create template: Template content is required' });
  });

  it('DB failure -> 400 {success:false, error: "Failed to create template: ..."}', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession() } });

    const res = await POST(req({ name: 'ok', content: 'hi' }));
    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Failed to create template: boom' });
  });
});
