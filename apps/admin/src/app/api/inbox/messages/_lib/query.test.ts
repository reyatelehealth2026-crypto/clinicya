/**
 * @jest-environment node
 */
import { makeFakeTenantDb } from '../../../../(tenant)/users/testHelpers/fakeTenantDb';
import {
  getInitialMessages,
  getMessagesCursor,
  phpIntCast,
  INITIAL_MESSAGES_LIMIT,
  type MessageRow,
} from './query';

function row(id: number, overrides: Partial<MessageRow> = {}): MessageRow {
  return {
    id,
    user_id: 7,
    direction: id % 2 === 0 ? 'incoming' : 'outgoing',
    message_type: 'text',
    content: `msg ${id}`,
    is_read: 1,
    sent_by: null,
    created_at: new Date('2026-07-14T03:00:00'),
    ...overrides,
  };
}

describe('phpIntCast', () => {
  it('parses the leading integer run, mirroring PHP (int) casts', () => {
    expect(phpIntCast('123')).toBe(123);
    expect(phpIntCast('123abc')).toBe(123);
    expect(phpIntCast('-5')).toBe(-5);
    expect(phpIntCast('abc')).toBe(0);
    expect(phpIntCast('')).toBe(0);
  });
});

describe('getMessagesCursor', () => {
  it('first page (cursor=null): queries WHERE user_id only, ORDER BY id DESC, LIMIT limit+1', async () => {
    const { db, queries } = makeFakeTenantDb(() => [row(10), row(9), row(8)]);
    const result = await getMessagesCursor(db, 7, null, 2);

    expect(queries).toHaveLength(1);
    expect(queries[0]?.sql).toContain('FROM messages');
    expect(queries[0]?.sql).toContain('ORDER BY id DESC');
    expect(queries[0]?.sql).not.toContain('id <');
    expect(queries[0]?.params).toEqual([7, 3]); // limit+1

    // 3 rows fetched for limit=2 -> has_more, extra (oldest, id=8) popped.
    expect(result.has_more).toBe(true);
    expect(result.next_cursor).toBe('9');
    expect(result.count).toBe(2);
    // Ascending (oldest-first) chat order.
    expect(result.messages.map((m) => m.id)).toEqual([9, 10]);
  });

  it('subsequent page (cursor set): adds AND id < ? with the phpIntCast(cursor) bound param', async () => {
    const { db, queries } = makeFakeTenantDb(() => [row(7), row(6)]);
    const result = await getMessagesCursor(db, 7, '8', 5);

    expect(queries[0]?.sql).toContain('id < ?');
    expect(queries[0]?.params).toEqual([7, 8, 6]); // user_id, cursor, limit+1
    expect(result.has_more).toBe(false); // 2 rows <= limit(5) -> no extra to pop
    expect(result.next_cursor).toBeNull();
    expect(result.messages.map((m) => m.id)).toEqual([6, 7]);
  });

  it('garbage (non-numeric) cursor phpIntCast()s to 0, matching PHP (int) cast', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await getMessagesCursor(db, 7, 'not-a-number', 50);
    expect(queries[0]?.params).toEqual([7, 0, 51]);
  });

  it('clamps limit to [1,100] internally (InboxService.php line 774), independent of any caller-side validation', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await getMessagesCursor(db, 7, null, 9999);
    expect(queries[0]?.params).toEqual([7, 101]); // clamped to 100, +1

    await getMessagesCursor(db, 7, null, 0);
    expect(queries[1]?.params).toEqual([7, 2]); // clamped to 1, +1
  });

  it('exact-limit page (no extra row returned): has_more=false, next_cursor=null', async () => {
    const { db } = makeFakeTenantDb(() => [row(3), row(2)]);
    const result = await getMessagesCursor(db, 7, null, 2);
    expect(result.has_more).toBe(false);
    expect(result.next_cursor).toBeNull();
    expect(result.count).toBe(2);
  });

  it('empty conversation: messages=[], has_more=false, next_cursor=null, count=0', async () => {
    const { db } = makeFakeTenantDb(() => []);
    const result = await getMessagesCursor(db, 999, null, 50);
    expect(result).toEqual({ messages: [], next_cursor: null, has_more: false, count: 0 });
  });

  it('serializes created_at as a MySQL DATETIME string (no "Z"/"T"), not a raw ISO string', async () => {
    const { db } = makeFakeTenantDb(() => [row(1, { created_at: new Date('2026-07-14T09:05:03') })]);
    const result = await getMessagesCursor(db, 7, null, 10);
    expect(result.messages[0]?.created_at).toBe('2026-07-14 09:05:03');
  });

  it('does not filter by line_account_id (literal InboxService::getMessagesCursor behavior, unlike offset getMessages())', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await getMessagesCursor(db, 7, null, 50);
    expect(queries[0]?.sql).not.toContain('line_account_id');
  });
});

// ---------------------------------------------------------------------------
// Golden fixture tests (brief acceptance criteria) — a fake DB whose
// queryImpl actually applies WHERE/ORDER BY/LIMIT semantics against a fixed
// in-memory fixture, instead of returning a canned per-call response. This
// lets the SAME fixture back multiple sequential cursor calls (a real
// pagination walk) and an independent reference computation.
// ---------------------------------------------------------------------------

const ALL_MESSAGE_TYPES = ['text', 'image', 'sticker', 'flex', 'file', 'video', 'audio', 'location'] as const;

/** Builds `count` messages (ids 1..count) round-robining through every message type this batch's MessageBubble renders, including a text-as-video case and realistic per-type content shapes. */
function buildFixture(count: number, userId = 7): MessageRow[] {
  return Array.from({ length: count }, (_, i) => {
    const id = i + 1;
    const type = ALL_MESSAGE_TYPES[i % ALL_MESSAGE_TYPES.length]!;
    let content = `content ${id}`;
    if (type === 'text' && id % 17 === 0) {
      content = 'https://cdn.example.com/uploads/line_videos/clip.mp4'; // text-as-video special case
    } else if (type === 'location') {
      content = `[location] Address ${id} (13.7563, 100.5018)`;
    } else if (type === 'flex') {
      content = JSON.stringify({ type: 'bubble', body: { type: 'box', contents: [{ type: 'text', text: `Flex ${id}` }] } });
    } else if (type === 'sticker') {
      content = JSON.stringify({ stickerId: id });
    } else if (type === 'file') {
      content = JSON.stringify({ name: `file-${id}.pdf`, url: `https://x/${id}.pdf` });
    }
    return row(id, { user_id: userId, message_type: type, content });
  });
}

/** A fake DB that applies real WHERE user_id / AND id < cursor / ORDER BY id DESC / LIMIT semantics against a fixed in-memory fixture, keyed off each query's compiled SQL text + bound params. */
function makeFixtureDb(fixture: MessageRow[]) {
  return makeFakeTenantDb((sqlText: string, params: unknown[]) => {
    const byUserDesc = (userId: number) =>
      fixture.filter((m) => m.user_id === userId).sort((a, b) => b.id - a.id);

    if (sqlText.includes(') recent')) {
      // getInitialMessages: SELECT * FROM (... ORDER BY id DESC LIMIT ?) recent ORDER BY id ASC
      const [userId, limit] = params as [number, number];
      return byUserDesc(userId)
        .slice(0, limit)
        .sort((a, b) => a.id - b.id);
    }
    if (sqlText.includes('id < ?')) {
      // getMessagesCursor with a cursor: WHERE user_id = ? AND id < ? ORDER BY id DESC LIMIT ?
      const [userId, cursor, limitPlus1] = params as [number, number, number];
      return byUserDesc(userId)
        .filter((m) => m.id < cursor)
        .slice(0, limitPlus1);
    }
    // getMessagesCursor, first page: WHERE user_id = ? ORDER BY id DESC LIMIT ?
    const [userId, limitPlus1] = params as [number, number];
    return byUserDesc(userId).slice(0, limitPlus1);
  });
}

describe('golden fixture tests', () => {
  it('cursor pagination over a 123-message fixture spanning every message type: no id overlap between pages, ascending-per-page order, has_more flips false once exhausted', async () => {
    const fixture = buildFixture(123);
    const { db } = makeFixtureDb(fixture);

    const page1 = await getMessagesCursor(db, 7, null, 50);
    expect(page1.count).toBe(50);
    expect(page1.has_more).toBe(true);
    expect(page1.messages.map((m) => m.id)).toEqual(Array.from({ length: 50 }, (_, i) => 74 + i)); // ids 74..123

    const page2 = await getMessagesCursor(db, 7, page1.next_cursor, 50);
    expect(page2.count).toBe(50);
    expect(page2.has_more).toBe(true);
    expect(page2.messages.map((m) => m.id)).toEqual(Array.from({ length: 50 }, (_, i) => 24 + i)); // ids 24..73

    const page3 = await getMessagesCursor(db, 7, page2.next_cursor, 50);
    expect(page3.count).toBe(23);
    expect(page3.has_more).toBe(false);
    expect(page3.next_cursor).toBeNull();
    expect(page3.messages.map((m) => m.id)).toEqual(Array.from({ length: 23 }, (_, i) => 1 + i)); // ids 1..23

    // Ascending order within every page.
    for (const page of [page1, page2, page3]) {
      for (let i = 1; i < page.messages.length; i++) {
        expect(page.messages[i]!.id).toBeGreaterThan(page.messages[i - 1]!.id);
      }
    }

    // No id overlap between any two pages, and the union is exactly 1..123.
    const allIds = [...page1.messages, ...page2.messages, ...page3.messages].map((m) => m.id);
    expect(new Set(allIds).size).toBe(123);
    expect([...allIds].sort((a, b) => a - b)).toEqual(Array.from({ length: 123 }, (_, i) => i + 1));
  });

  it('getInitialMessages matches a direct "ORDER BY id DESC LIMIT 300 then reverse" reference computed independently over the same fixture (350 messages -> exactly 300 returned)', async () => {
    const fixture = buildFixture(350);
    const { db } = makeFixtureDb(fixture);

    const result = await getInitialMessages(db, 7);
    const reference = [...fixture].sort((a, b) => b.id - a.id).slice(0, 300).reverse();

    expect(result).toHaveLength(300);
    expect(result.map((m) => m.id)).toEqual(reference.map((m) => m.id));
    // ascending order end-to-end
    for (let i = 1; i < result.length; i++) {
      expect(result[i]!.id).toBeGreaterThan(result[i - 1]!.id);
    }
  });

  it('getInitialMessages matches the same reference method when the conversation has FEWER than 300 messages (87 -> exactly 87 returned)', async () => {
    const fixture = buildFixture(87);
    const { db } = makeFixtureDb(fixture);

    const result = await getInitialMessages(db, 7);
    const reference = [...fixture].sort((a, b) => b.id - a.id).slice(0, 300).reverse();

    expect(result).toHaveLength(87);
    expect(result.map((m) => m.id)).toEqual(reference.map((m) => m.id));
  });

  it('JSON response shape for a representative fixture is field-for-field + key-ORDER identical to api/inbox-v2.php action=getMessages (snake_case keys, nesting: {success,data:{messages,next_cursor,has_more,count}})', async () => {
    const fixture = [
      row(1, { user_id: 7, direction: 'incoming', message_type: 'text', content: 'สวัสดีครับ', is_read: 1, sent_by: null, created_at: new Date(2026, 6, 14, 9, 5, 3) }),
      row(2, { user_id: 7, direction: 'outgoing', message_type: 'text', content: 'สวัสดีค่ะ', is_read: 0, sent_by: 'admin:เภสัชกร', created_at: new Date(2026, 6, 14, 9, 6, 10) }),
    ];
    const { db } = makeFixtureDb(fixture);

    const data = await getMessagesCursor(db, 7, null, 50);
    const envelope = { success: true, data };

    // Top-level envelope key order: success, data (matches sendResponse(['success'=>true,'data'=>$result])).
    expect(Object.keys(envelope)).toEqual(['success', 'data']);
    // data key order: messages, next_cursor, has_more, count (matches InboxService::getMessagesCursor()'s literal return array).
    expect(Object.keys(envelope.data)).toEqual(['messages', 'next_cursor', 'has_more', 'count']);
    // Per-message key order: id, user_id, direction, message_type, content, is_read, sent_by, created_at
    // (matches the SELECT column list PDO::FETCH_ASSOC preserves — InboxService.php lines 779-788).
    expect(Object.keys(envelope.data.messages[0]!)).toEqual([
      'id',
      'user_id',
      'direction',
      'message_type',
      'content',
      'is_read',
      'sent_by',
      'created_at',
    ]);

    expect(envelope).toEqual({
      success: true,
      data: {
        messages: [
          { id: 1, user_id: 7, direction: 'incoming', message_type: 'text', content: 'สวัสดีครับ', is_read: 1, sent_by: null, created_at: '2026-07-14 09:05:03' },
          { id: 2, user_id: 7, direction: 'outgoing', message_type: 'text', content: 'สวัสดีค่ะ', is_read: 0, sent_by: 'admin:เภสัชกร', created_at: '2026-07-14 09:06:10' },
        ],
        next_cursor: null,
        has_more: false,
        count: 2,
      },
    });
  });
});

describe('getInitialMessages', () => {
  it('queries the nested "DESC LIMIT N, then ASC" subquery form, defaulting to 300', async () => {
    const { db, queries } = makeFakeTenantDb(() => [row(1), row(2), row(3)]);
    const rows = await getInitialMessages(db, 7);

    expect(queries).toHaveLength(1);
    const sqlText = queries[0]?.sql ?? '';
    expect(sqlText).toContain('SELECT * FROM');
    expect(sqlText).toContain('ORDER BY id DESC');
    expect(sqlText).toContain(') recent');
    expect(sqlText).toContain('ORDER BY id ASC');
    expect(queries[0]?.params).toEqual([7, INITIAL_MESSAGES_LIMIT]);
    expect(rows).toHaveLength(3);
    // created_at stays a Date (no JSON string conversion) — SSR-only, no wire boundary.
    expect(rows[0]?.created_at).toBeInstanceOf(Date);
  });

  it('accepts a custom limit override', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await getInitialMessages(db, 7, 50);
    expect(queries[0]?.params).toEqual([7, 50]);
  });

  it('fewer than the limit exist: returns exactly what is there', async () => {
    const { db } = makeFakeTenantDb(() => [row(1), row(2)]);
    const rows = await getInitialMessages(db, 7);
    expect(rows).toHaveLength(2);
  });
});
