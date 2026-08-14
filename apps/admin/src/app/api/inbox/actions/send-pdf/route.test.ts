/**
 * @jest-environment node
 */
const mockResolveInboxApiContext = jest.fn();
jest.mock('./_lib/session', () => ({
  resolveInboxApiContext: () => mockResolveInboxApiContext(),
}));

const mockLineSendMessage = jest.fn();
jest.mock('@reya/line', () => ({
  sendMessage: (...args: unknown[]) => mockLineSendMessage(...args),
}));

import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import type { NextRequest } from 'next/server';
import type { TenantSession } from '@reya/auth';
import { makeFakeTenantDb, type RecordedQuery } from './_lib/testHelpers/fakeTenantDb';
import { POST } from './route';

let tmpDir: string;

beforeEach(() => {
  tmpDir = mkdtempSync(path.join(tmpdir(), 'inbox-chat-files-'));
  process.env.INBOX_CHAT_FILES_UPLOAD_DIR = tmpDir;
  jest.clearAllMocks();
});

afterEach(() => {
  delete process.env.INBOX_CHAT_FILES_UPLOAD_DIR;
  rmSync(tmpDir, { recursive: true, force: true });
});

function makeForm(fields: Record<string, string>, file?: { field: string; name: string; type: string; bytes: number }): FormData {
  const form = new FormData();
  for (const [k, v] of Object.entries(fields)) {
    form.append(k, v);
  }
  if (file) {
    const content = new Uint8Array(file.bytes).fill(9);
    form.append(file.field, new File([content], file.name, { type: file.type }));
  }
  return form;
}

function req(form: FormData, url = 'https://tenant-1234.re-ya.com/api/inbox/actions/send-pdf'): NextRequest {
  return { formData: async () => form, url } as unknown as NextRequest;
}

function fakeSession(overrides: Partial<TenantSession> = {}): TenantSession {
  return {
    realm: 'tenant',
    sid: 'sid',
    adminUserId: 7,
    tenantId: 1,
    currentBotId: 3,
    role: 'admin',
    username: 'pharmacist1',
    displayName: 'Pharmacist One',
    createdAt: '2026-07-01T00:00:00.000Z',
    lastSeenAt: '2026-07-14T00:00:00.000Z',
    expiresAt: '2026-07-15T00:00:00.000Z',
    ...overrides,
  };
}

function wireFakeDb(
  queryImpl: (sqlText: string, params: unknown[]) => unknown = () => [],
  sessionOverrides: Partial<TenantSession> = {}
): RecordedQuery[] {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockResolveInboxApiContext.mockResolvedValue({ ok: true, value: { db, session: fakeSession(sessionOverrides) } });
  return queries;
}

const USER_ROW = {
  id: 42,
  line_user_id: 'Uabc123',
  line_account_id: 9,
  reply_token: 'replytok',
  reply_token_expires_str: '2026-07-14 12:00:00',
};

describe('POST /api/inbox/actions/send-pdf', () => {
  it('401 JSON when unauthenticated, LINE API never touched', async () => {
    mockResolveInboxApiContext.mockResolvedValue({ ok: false, status: 401 });

    const res = await POST(req(makeForm({ user_id: '42' })));

    expect(res.status).toBe(401);
    expect(await res.json()).toEqual({ success: false, error: 'Unauthorized' });
    expect(mockLineSendMessage).not.toHaveBeenCalled();
  });

  it('400 "User ID required" when user_id is missing/falsy, no DB queries issued', async () => {
    const queries = wireFakeDb();

    const res = await POST(req(makeForm({}, { field: 'pdf', name: 'doc.pdf', type: 'application/pdf', bytes: 10 })));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'User ID required' });
    expect(queries).toHaveLength(0);
    expect(mockLineSendMessage).not.toHaveBeenCalled();
  });

  it('400 "No PDF uploaded" when the pdf field is absent', async () => {
    wireFakeDb();

    const res = await POST(req(makeForm({ user_id: '42' })));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'No PDF uploaded' });
    expect(mockLineSendMessage).not.toHaveBeenCalled();
  });

  it('400 "Invalid file type. Only PDF allowed" for a non-PDF MIME (exact match, no allow-set)', async () => {
    wireFakeDb();

    const form = makeForm({ user_id: '42' }, { field: 'pdf', name: 'a.png', type: 'image/png', bytes: 10 });
    const res = await POST(req(form));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'Invalid file type. Only PDF allowed' });
  });

  it('400 "PDF too large. Max 10MB" over the size cap', async () => {
    wireFakeDb();

    const form = makeForm({ user_id: '42' }, { field: 'pdf', name: 'doc.pdf', type: 'application/pdf', bytes: 10 * 1024 * 1024 + 1 });
    const res = await POST(req(form));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'PDF too large. Max 10MB' });
  });

  it('400 "User not found" when the user row does not exist', async () => {
    wireFakeDb(() => []);

    const form = makeForm({ user_id: '999' }, { field: 'pdf', name: 'doc.pdf', type: 'application/pdf', bytes: 10 });
    const res = await POST(req(form));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({ success: false, error: 'User not found' });
    expect(mockLineSendMessage).not.toHaveBeenCalled();
  });

  it('400 when the user has no matching line_accounts row (Next-side addition) — the just-written file is cleaned up', async () => {
    wireFakeDb((sqlText) => {
      if (sqlText.includes('FROM users WHERE id')) return [USER_ROW];
      if (sqlText.includes('FROM line_accounts WHERE id')) return [];
      return [];
    });

    const form = makeForm({ user_id: '42' }, { field: 'pdf', name: 'doc.pdf', type: 'application/pdf', bytes: 10 });
    const res = await POST(req(form));

    expect(res.status).toBe(400);
    const body = await res.json();
    expect(body.success).toBe(false);
    expect(typeof body.error).toBe('string');
    expect(mockLineSendMessage).not.toHaveBeenCalled();

    const fs = await import('node:fs');
    expect(fs.readdirSync(tmpDir)).toHaveLength(0);
  });

  it('happy path: writes the file (.pdf always), sends via LINE as text+link, inserts messages + activity_logs, returns the exact envelope', async () => {
    mockLineSendMessage.mockResolvedValue({ code: 200, method: 'reply', body: {} });
    const queries = wireFakeDb((sqlText) => {
      if (sqlText.includes('FROM users WHERE id')) return [USER_ROW];
      if (sqlText.includes('FROM line_accounts WHERE id')) return [{ channel_access_token: 'token-abc' }];
      const lower = sqlText.toLowerCase();
      if (lower.includes('insert into `messages`')) return { insertId: 555, affectedRows: 1 };
      if (lower.includes('insert into `activity_logs`')) return { insertId: 999, affectedRows: 1 };
      if (lower.includes('update `users`')) return { affectedRows: 1 };
      return [];
    });

    // Original filename has no .pdf extension in its own name on purpose, to prove the on-disk
    // filename ALWAYS gets '.pdf' regardless (inbox-v2.php: original extension never inspected).
    const form = makeForm({ user_id: '42' }, { field: 'pdf', name: 'ใบสั่งยา คนไข้.report', type: 'application/pdf', bytes: 200 });
    const res = await POST(req(form, 'https://tenant-1234.re-ya.com/api/inbox/actions/send-pdf'));
    const body = await res.json();

    // --- @reya/line sendMessage() call shape ---
    expect(mockLineSendMessage).toHaveBeenCalledTimes(1);
    const [params, options] = mockLineSendMessage.mock.calls[0] as [Record<string, unknown>, Record<string, unknown>];
    expect(params.userId).toBe('Uabc123');
    const fileUrl = body.file_url as string;
    expect(fileUrl).toMatch(/^https:\/\/tenant-1234\.re-ya\.com\/uploads\/chat_files\/pdf_\d+_[0-9a-f]+\.pdf$/);
    expect(params.messages).toEqual([{ type: 'text', text: `📄 ไฟล์ PDF: ใบสั่งยา คนไข้.report\n🔗 ${fileUrl}` }]);
    expect(params.replyToken).toBe('replytok');
    expect(params.tokenExpires).toBe('2026-07-14 12:00:00');
    expect(params.internalUserId).toBe(42);
    expect(options).toEqual({ channelAccessToken: 'token-abc' });

    // --- File actually written to the resolved (env-overridden) upload dir, as .pdf ---
    const filename = fileUrl.split('/').pop()!;
    expect(filename).toMatch(/\.pdf$/);
    const written = readFileSync(path.join(tmpDir, filename));
    expect(written.length).toBe(200);

    // --- onReplyTokenUsed callback ---
    await (params.onReplyTokenUsed as (info: unknown) => Promise<void>)({ lineUserId: 'Uabc123', internalUserId: 42 });
    const updateQuery = queries.find((q) => q.sql.toLowerCase().includes('update `users`'));
    expect(updateQuery?.params).toEqual([null, null, 42]);

    // --- messages INSERT: message_type='file', content=JSON.stringify({url,name}) ---
    const messagesInsert = queries.find((q) => q.sql.toLowerCase().includes('insert into `messages`'));
    expect(messagesInsert?.params).toEqual([
      9,
      42,
      'outgoing',
      'file',
      JSON.stringify({ url: fileUrl, name: 'ใบสั่งยา คนไข้.report' }),
      'admin:pharmacist1',
      0,
    ]);

    // --- activity_logs INSERT: new_value is NOT set ---
    const activityInsert = queries.find((q) => q.sql.toLowerCase().includes('insert into `activity_logs`'));
    expect(activityInsert?.sql.toLowerCase()).not.toContain('new_value');
    expect(activityInsert?.params).toEqual(['message', 'send', 'ส่งไฟล์ PDF ถึงลูกค้า', 42, 7, 'pharmacist1', 'message', 555, 9]);

    // --- response envelope: file_name is the ORIGINAL filename, no method/method_label keys ---
    expect(res.status).toBe(200);
    expect(body).toEqual({
      success: true,
      message_id: 555,
      file_url: fileUrl,
      file_name: 'ใบสั่งยา คนไข้.report',
      time: expect.stringMatching(/^\d{2}:\d{2}$/),
      sent_by: 'admin:pharmacist1',
    });
  });

  it('non-200 LINE result -> 400 with the literal "Failed to send PDF via LINE (HTTP ..., ...): ..." message; file deleted; no DB writes', async () => {
    mockLineSendMessage.mockResolvedValue({ code: 400, method: 'push', body: { message: 'bad request' } });
    const queries = wireFakeDb((sqlText) => {
      if (sqlText.includes('FROM users WHERE id')) return [USER_ROW];
      if (sqlText.includes('FROM line_accounts WHERE id')) return [{ channel_access_token: 'token-abc' }];
      return [];
    });

    const form = makeForm({ user_id: '42' }, { field: 'pdf', name: 'doc.pdf', type: 'application/pdf', bytes: 10 });
    const res = await POST(req(form));

    expect(res.status).toBe(400);
    expect(await res.json()).toEqual({
      success: false,
      error: `Failed to send PDF via LINE (HTTP 400, push): ${JSON.stringify({ message: 'bad request' })}`,
    });
    expect(queries.some((q) => q.sql.toLowerCase().includes('insert into `messages`'))).toBe(false);
    expect(queries.some((q) => q.sql.toLowerCase().includes('insert into `activity_logs`'))).toBe(false);

    const fs = await import('node:fs');
    expect(fs.readdirSync(tmpDir)).toHaveLength(0);
  });
});
