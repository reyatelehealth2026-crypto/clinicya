const mockMulticastMessage = jest.fn();
const mockBroadcastMessage = jest.fn();
jest.mock('@reya/line', () => ({
  multicastMessage: (...args: unknown[]) => mockMulticastMessage(...args),
  broadcastMessage: (...args: unknown[]) => mockBroadcastMessage(...args),
}));

import { makeFakeTenantDb } from '../../users/testHelpers/fakeTenantDb';
import { chunkedMulticast, executeBroadcastSend, executeProductBroadcastSend } from './broadcastFanout';

const LINE_OPTIONS = { channelAccessToken: 'tok' };
const MESSAGES = [{ type: 'text', text: 'hello' }];

beforeEach(() => {
  jest.clearAllMocks();
});

describe('chunkedMulticast — array_chunk(...,500) + sum-on-200 loop', () => {
  it('makes exactly 1 call for <=500 recipients', async () => {
    mockMulticastMessage.mockResolvedValue({ code: 200, body: {} });
    const ids = Array.from({ length: 500 }, (_, i) => `U${i}`);
    const sent = await chunkedMulticast(ids, MESSAGES, LINE_OPTIONS);
    expect(mockMulticastMessage).toHaveBeenCalledTimes(1);
    expect(sent).toBe(500);
  });

  it('chunks into ceil(N/500) calls for N > 500, and each chunk carries <=500 ids', async () => {
    mockMulticastMessage.mockResolvedValue({ code: 200, body: {} });
    const ids = Array.from({ length: 1234 }, (_, i) => `U${i}`);
    const sent = await chunkedMulticast(ids, MESSAGES, LINE_OPTIONS);
    expect(mockMulticastMessage).toHaveBeenCalledTimes(3); // ceil(1234/500) = 3
    expect((mockMulticastMessage.mock.calls[0]?.[0] as string[]).length).toBe(500);
    expect((mockMulticastMessage.mock.calls[1]?.[0] as string[]).length).toBe(500);
    expect((mockMulticastMessage.mock.calls[2]?.[0] as string[]).length).toBe(234);
    expect(sent).toBe(1234);
  });

  it('a non-200 chunk response does not increment sentCount, but later chunks still run', async () => {
    mockMulticastMessage
      .mockResolvedValueOnce({ code: 500, body: {} })
      .mockResolvedValueOnce({ code: 200, body: {} });
    const ids = Array.from({ length: 1000 }, (_, i) => `U${i}`);
    const sent = await chunkedMulticast(ids, MESSAGES, LINE_OPTIONS);
    expect(mockMulticastMessage).toHaveBeenCalledTimes(2);
    expect(sent).toBe(500); // only the second (200) chunk counted
  });

  it('zero recipients makes zero calls', async () => {
    const sent = await chunkedMulticast([], MESSAGES, LINE_OPTIONS);
    expect(mockMulticastMessage).not.toHaveBeenCalled();
    expect(sent).toBe(0);
  });
});

describe('executeBroadcastSend — classes/BroadcastHelper.php::executeBroadcastSend() (send.php reachable types)', () => {
  it("'database': queries non-blocked users scoped by line_account_id, then chunked-multicasts", async () => {
    mockMulticastMessage.mockResolvedValue({ code: 200, body: {} });
    const { db, queries } = makeFakeTenantDb(() => [{ line_user_id: 'U1' }, { line_user_id: 'U2' }]);
    const result = await executeBroadcastSend({
      db,
      currentBotId: 9,
      lineOptions: LINE_OPTIONS,
      targetType: 'database',
      messages: MESSAGES,
    });
    expect(result.sentCount).toBe(2);
    expect(result.targetGroupId).toBeNull();
    expect(queries[0]?.sql).toContain('is_blocked = 0');
    expect(mockMulticastMessage).toHaveBeenCalledWith(['U1', 'U2'], MESSAGES, LINE_OPTIONS);
  });

  it("'all': calls broadcastMessage; on 200, sentCount = a fresh COUNT(*) of non-blocked users (NOT -1)", async () => {
    mockBroadcastMessage.mockResolvedValue({ code: 200, body: {} });
    const { db } = makeFakeTenantDb(() => [{ c: 777 }]);
    const result = await executeBroadcastSend({
      db,
      currentBotId: 9,
      lineOptions: LINE_OPTIONS,
      targetType: 'all',
      messages: MESSAGES,
    });
    expect(result.sentCount).toBe(777);
    expect(mockBroadcastMessage).toHaveBeenCalledWith(MESSAGES, LINE_OPTIONS);
  });

  it("'all': sentCount is 0 (not -1, not a stale count) when broadcastMessage fails", async () => {
    mockBroadcastMessage.mockResolvedValue({ code: 500, body: {} });
    const { db, queries } = makeFakeTenantDb(() => [{ c: 999 }]);
    const result = await executeBroadcastSend({
      db,
      currentBotId: 9,
      lineOptions: LINE_OPTIONS,
      targetType: 'all',
      messages: MESSAGES,
    });
    expect(result.sentCount).toBe(0);
    expect(queries).toHaveLength(0); // COUNT(*) never issued on failure
  });

  it("'segment': joins segment_members, extracts line_user_id, chunked-multicasts", async () => {
    mockMulticastMessage.mockResolvedValue({ code: 200, body: {} });
    const { db, queries } = makeFakeTenantDb(() => [{ line_user_id: 'U1' }, { line_user_id: null }]);
    const result = await executeBroadcastSend({
      db,
      currentBotId: 9,
      lineOptions: LINE_OPTIONS,
      targetType: 'segment',
      segmentId: 3,
      messages: MESSAGES,
    });
    expect(result.sentCount).toBe(1); // the null line_user_id row is filtered out
    expect(queries[0]?.sql).toContain('segment_members');
    expect(queries[0]?.params).toEqual([3]);
  });

  it("'tag': issues one getUsersByTag-equivalent query PER tag id, deduplicates across tags, then chunked-multicasts once", async () => {
    mockMulticastMessage.mockResolvedValue({ code: 200, body: {} });
    const { db, queries } = makeFakeTenantDb((sqlText, params) => {
      if ((params as number[])[0] === 10) return [{ line_user_id: 'U1' }, { line_user_id: 'U2' }];
      if ((params as number[])[0] === 20) return [{ line_user_id: 'U2' }, { line_user_id: 'U3' }]; // U2 overlaps
      return [];
    });
    const result = await executeBroadcastSend({
      db,
      currentBotId: 9,
      lineOptions: LINE_OPTIONS,
      targetType: 'tag',
      tagIds: [10, 20],
      messages: MESSAGES,
    });
    expect(queries).toHaveLength(2); // one query per tag id
    expect(result.sentCount).toBe(3); // U1, U2, U3 deduplicated
    expect(mockMulticastMessage).toHaveBeenCalledTimes(1);
    const sentIds = mockMulticastMessage.mock.calls[0]?.[0] as string[];
    expect(new Set(sentIds)).toEqual(new Set(['U1', 'U2', 'U3']));
  });

  it("'group': a SINGLE unchunked multicastMessage call even with >500 members (matches PHP's un-chunked group branch)", async () => {
    mockMulticastMessage.mockResolvedValue({ code: 200, body: {} });
    const ids = Array.from({ length: 600 }, (_, i) => ({ line_user_id: `U${i}` }));
    const { db, queries } = makeFakeTenantDb(() => ids);
    const result = await executeBroadcastSend({
      db,
      currentBotId: 9,
      lineOptions: LINE_OPTIONS,
      targetType: 'group',
      targetGroupId: '5',
      messages: MESSAGES,
    });
    expect(mockMulticastMessage).toHaveBeenCalledTimes(1);
    expect((mockMulticastMessage.mock.calls[0]?.[0] as string[]).length).toBe(600);
    expect(result.sentCount).toBe(600);
    expect(result.targetGroupId).toBe('5'); // echoed back unchanged, BroadcastHelper.php:122
    expect(queries[0]?.sql).toContain('user_groups');
  });

  it("'group': sentCount is 0 and no LINE call at all when the group has no members", async () => {
    const { db } = makeFakeTenantDb(() => []);
    const result = await executeBroadcastSend({
      db,
      currentBotId: 9,
      lineOptions: LINE_OPTIONS,
      targetType: 'group',
      targetGroupId: '5',
      messages: MESSAGES,
    });
    expect(result.sentCount).toBe(0);
    expect(mockMulticastMessage).not.toHaveBeenCalled();
  });
});

describe('executeProductBroadcastSend — products.php send_broadcast (its own inline targeting, not BroadcastHelper)', () => {
  it("'all': sentCount is the -1 sentinel on a successful broadcastMessage (PHP's own display oddity, ported as-is)", async () => {
    mockBroadcastMessage.mockResolvedValue({ code: 200, body: {} });
    const { db } = makeFakeTenantDb(() => []);
    const result = await executeProductBroadcastSend({
      db,
      currentBotId: 9,
      lineOptions: LINE_OPTIONS,
      targetType: 'all',
      messages: MESSAGES,
    });
    expect(result.sentCount).toBe(-1);
  });

  it("'all': sentCount is 0 (not -1) when broadcastMessage fails", async () => {
    mockBroadcastMessage.mockResolvedValue({ code: 500, body: {} });
    const { db } = makeFakeTenantDb(() => []);
    const result = await executeProductBroadcastSend({
      db,
      currentBotId: 9,
      lineOptions: LINE_OPTIONS,
      targetType: 'all',
      messages: MESSAGES,
    });
    expect(result.sentCount).toBe(0);
  });

  it("'tags': DISTINCT-IN-clause query scoped by line_account_id, then chunked-multicasts", async () => {
    mockMulticastMessage.mockResolvedValue({ code: 200, body: {} });
    const { db, queries } = makeFakeTenantDb(() => [{ line_user_id: 'U1' }, { line_user_id: 'U2' }]);
    const result = await executeProductBroadcastSend({
      db,
      currentBotId: 9,
      lineOptions: LINE_OPTIONS,
      targetType: 'tags',
      targetTagIds: [1, 2, 3],
      messages: MESSAGES,
    });
    expect(result.sentCount).toBe(2);
    expect(queries[0]?.sql).toContain('DISTINCT');
    expect(queries[0]?.sql).toContain('IN (');
    expect(queries[0]?.params).toEqual([1, 2, 3, 9]);
  });

  it("'tags': zero tag ids selected short-circuits to sentCount 0 with NO DB query and NO LINE call", async () => {
    const { db, queries } = makeFakeTenantDb(() => {
      throw new Error('should not query with an empty IN() clause');
    });
    const result = await executeProductBroadcastSend({
      db,
      currentBotId: 9,
      lineOptions: LINE_OPTIONS,
      targetType: 'tags',
      targetTagIds: [],
      messages: MESSAGES,
    });
    expect(result.sentCount).toBe(0);
    expect(queries).toHaveLength(0);
    expect(mockMulticastMessage).not.toHaveBeenCalled();
  });

  it("'tags': the query matching zero users short-circuits to sentCount 0 with no LINE call", async () => {
    const { db } = makeFakeTenantDb(() => []);
    const result = await executeProductBroadcastSend({
      db,
      currentBotId: 9,
      lineOptions: LINE_OPTIONS,
      targetType: 'tags',
      targetTagIds: [1],
      messages: MESSAGES,
    });
    expect(result.sentCount).toBe(0);
    expect(mockMulticastMessage).not.toHaveBeenCalled();
  });

  it("'tags' chunking: N > 500 matched users are chunked into ceil(N/500) multicastMessage calls", async () => {
    mockMulticastMessage.mockResolvedValue({ code: 200, body: {} });
    const rows = Array.from({ length: 900 }, (_, i) => ({ line_user_id: `U${i}` }));
    const { db } = makeFakeTenantDb(() => rows);
    const result = await executeProductBroadcastSend({
      db,
      currentBotId: 9,
      lineOptions: LINE_OPTIONS,
      targetType: 'tags',
      targetTagIds: [1],
      messages: MESSAGES,
    });
    expect(mockMulticastMessage).toHaveBeenCalledTimes(2); // ceil(900/500) = 2
    expect(result.sentCount).toBe(900);
  });
});
