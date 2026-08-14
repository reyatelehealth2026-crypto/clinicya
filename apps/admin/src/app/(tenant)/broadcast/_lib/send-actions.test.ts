import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import type { TenantSession } from '@reya/auth';
import { makeFakeTenantDb, type RecordedQuery } from '../../users/testHelpers/fakeTenantDb';

const mockRequireTenantPageContext = jest.fn();
jest.mock('../../users/_lib/session', () => ({
  requireTenantPageContext: () => mockRequireTenantPageContext(),
}));

const mockRedirect = jest.fn((url: string) => {
  throw new Error(`REDIRECT:${url}`);
});
jest.mock('next/navigation', () => ({
  redirect: (url: string) => mockRedirect(url),
}));

const mockMulticastMessage = jest.fn();
const mockBroadcastMessage = jest.fn();
jest.mock('@reya/line', () => ({
  multicastMessage: (...args: unknown[]) => mockMulticastMessage(...args),
  broadcastMessage: (...args: unknown[]) => mockBroadcastMessage(...args),
}));

import { cancelScheduledAction, sendBroadcastAction } from './send-actions';

function fakeSession(currentBotId: number | null = 9): TenantSession {
  return {
    realm: 'tenant',
    sid: 'sid',
    adminUserId: 1,
    tenantId: 1,
    currentBotId,
    role: 'admin',
    username: 'admin1',
    displayName: 'Admin One',
    createdAt: new Date().toISOString(),
    lastSeenAt: new Date().toISOString(),
    expiresAt: new Date().toISOString(),
  };
}

function wireFakeDb(
  queryImpl: (sqlText: string, params: unknown[]) => unknown = () => [],
  currentBotId: number | null = 9
): { db: Kysely<TenantDB>; queries: RecordedQuery[] } {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockRequireTenantPageContext.mockResolvedValue({ db, session: fakeSession(currentBotId) });
  return { db, queries };
}

function formData(fields: Record<string, string | string[]>): FormData {
  const fd = new FormData();
  for (const [k, v] of Object.entries(fields)) {
    if (Array.isArray(v)) {
      for (const item of v) fd.append(k, item);
    } else {
      fd.set(k, v);
    }
  }
  return fd;
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('cancelScheduledAction — send.php lines 27-37', () => {
  it('UPDATEs status to failed WHERE id=? AND status=\'scheduled\' AND account-scoped, then redirects', async () => {
    const { queries } = wireFakeDb();
    await expect(cancelScheduledAction(formData({ broadcast_id: '42' }))).rejects.toThrow(
      'REDIRECT:/broadcast?tab=send&cancelled=1'
    );
    expect(queries).toHaveLength(1);
    expect(queries[0]?.sql).toContain("SET status = 'failed'");
    expect(queries[0]?.sql).toContain("status = 'scheduled'");
    expect(queries[0]?.sql).toContain('line_account_id');
    expect(queries[0]?.params).toEqual([42, 9]);
  });

  it('a falsy/missing broadcast_id skips the UPDATE entirely but still redirects (matches PHP\'s `if ($broadcastId)` guard)', async () => {
    const { queries } = wireFakeDb();
    await expect(cancelScheduledAction(formData({ broadcast_id: '0' }))).rejects.toThrow(
      'REDIRECT:/broadcast?tab=send&cancelled=1'
    );
    expect(queries).toHaveLength(0);

    const second = wireFakeDb();
    await expect(cancelScheduledAction(formData({}))).rejects.toThrow('REDIRECT:/broadcast?tab=send&cancelled=1');
    expect(second.queries).toHaveLength(0);
  });
});

describe('sendBroadcastAction — send_mode=\'schedule\' path (send.php lines 79-92)', () => {
  it('INSERTs status=\'scheduled\' with sent_count 0, and calls NO @reya/line function at all', async () => {
    const { queries } = wireFakeDb();
    await expect(
      sendBroadcastAction(
        formData({
          action: 'send',
          title: 'ลด 20% เดือนนี้',
          message_type: 'text',
          content: 'สวัสดีค่ะ ลดราคาพิเศษ',
          target_type: 'database',
          send_mode: 'schedule',
          scheduled_at: '2026-09-01T10:00',
        })
      )
    ).rejects.toThrow('REDIRECT:/broadcast?tab=send&scheduled=1');

    expect(queries).toHaveLength(1);
    expect(queries[0]?.sql).toContain('INSERT INTO broadcasts');
    expect(queries[0]?.sql).toContain("'scheduled'");
    expect(queries[0]?.params).toEqual(
      expect.arrayContaining([9, 'ลด 20% เดือนนี้', 'text', 'สวัสดีค่ะ ลดราคาพิเศษ', 'database', null])
    );
    expect(mockMulticastMessage).not.toHaveBeenCalled();
    expect(mockBroadcastMessage).not.toHaveBeenCalled();
  });

  it('target_type=\'tag\' JSON-encodes the selected tag ids into target_group_id, even on the schedule path', async () => {
    const { queries } = wireFakeDb();
    await expect(
      sendBroadcastAction(
        formData({
          action: 'send',
          title: 'แจ้งเตือน tag',
          message_type: 'text',
          content: 'hi',
          target_type: 'tag',
          'tag_ids[]': ['3', '7'],
          send_mode: 'schedule',
          scheduled_at: '2026-09-01T10:00',
        })
      )
    ).rejects.toThrow('REDIRECT:/broadcast?tab=send&scheduled=1');
    const params = queries[0]?.params as unknown[];
    expect(params).toContain(JSON.stringify([3, 7]));
  });
});

describe('sendBroadcastAction — send_mode=\'now\' path (send.php lines 94-116)', () => {
  it('database target: resolves the channel token, chunk-multicasts, INSERTs status=\'sent\' with the real sentCount, writes activity_logs, redirects with ?sent=N', async () => {
    mockMulticastMessage.mockResolvedValue({ code: 200, body: {} });
    const { queries } = wireFakeDb((sqlText) => {
      if (sqlText.includes('FROM line_accounts')) return [{ channel_access_token: 'tok-123' }];
      if (sqlText.includes('FROM users WHERE is_blocked')) return [{ line_user_id: 'U1' }, { line_user_id: 'U2' }];
      return [];
    });

    await expect(
      sendBroadcastAction(
        formData({
          action: 'send',
          title: 'ส่งทันที',
          message_type: 'text',
          content: 'ข้อความทดสอบ',
          target_type: 'database',
          send_mode: 'now',
        })
      )
    ).rejects.toThrow('REDIRECT:/broadcast?tab=send&sent=2');

    expect(mockMulticastMessage).toHaveBeenCalledWith(['U1', 'U2'], [{ type: 'text', text: 'ข้อความทดสอบ' }], {
      channelAccessToken: 'tok-123',
    });

    const insertBroadcast = queries.find((q) => q.sql.includes('INSERT INTO broadcasts'));
    expect(insertBroadcast?.sql).toContain("'sent'");
    expect(insertBroadcast?.params).toEqual(expect.arrayContaining([2]));

    const insertActivity = queries.find((q) => q.sql.includes('INSERT INTO `activity_logs`') || q.sql.includes('insert into `activity_logs`') || q.sql.toLowerCase().includes('insert into `activity_logs`'));
    expect(insertActivity).toBeDefined();
  });

  it('throws (no broadcasts row written) when currentBotId has no matching line_accounts row', async () => {
    const { queries } = wireFakeDb((sqlText) => {
      if (sqlText.includes('FROM line_accounts')) return [];
      return [];
    });

    await expect(
      sendBroadcastAction(
        formData({
          action: 'send',
          title: 'x',
          message_type: 'text',
          content: 'x',
          target_type: 'database',
          send_mode: 'now',
        })
      )
    ).rejects.toThrow(/ไม่พบการเชื่อมต่อ LINE OA/);

    expect(queries.find((q) => q.sql.includes('INSERT INTO broadcasts'))).toBeUndefined();
    expect(mockMulticastMessage).not.toHaveBeenCalled();
    expect(mockRedirect).not.toHaveBeenCalled();
  });

  it('flex message_type with invalid JSON stores contents:null (json_decode()-on-failure parity, not a validation error)', async () => {
    mockMulticastMessage.mockResolvedValue({ code: 200, body: {} });
    const { queries } = wireFakeDb((sqlText) => {
      if (sqlText.includes('FROM line_accounts')) return [{ channel_access_token: 'tok' }];
      if (sqlText.includes('FROM users WHERE is_blocked')) return [{ line_user_id: 'U1' }];
      return [];
    });

    await expect(
      sendBroadcastAction(
        formData({
          action: 'send',
          title: 'flex title',
          message_type: 'flex',
          flex_content: '{not valid json',
          target_type: 'database',
          send_mode: 'now',
        })
      )
    ).rejects.toThrow('REDIRECT:/broadcast?tab=send&sent=1');

    expect(mockMulticastMessage).toHaveBeenCalledWith(
      ['U1'],
      [{ type: 'flex', altText: 'flex title', contents: null }],
      { channelAccessToken: 'tok' }
    );
    const insertBroadcast = queries.find((q) => q.sql.includes('INSERT INTO broadcasts'));
    expect(insertBroadcast?.params).toEqual(expect.arrayContaining(['{not valid json']));
  });
});
