import { makeFakeTenantDb, type RecordedQuery } from './testHelpers/fakeTenantDb';
import {
  CONVERSATIONS_LIMIT_DEFAULT,
  fetchConversationsPage,
  getAssignedAdminIdsBatch,
  getConversationsDelta,
  getLastMessageMetaBatch,
  getUnreadCountsBatch,
  getUserTagsBatch,
  parseConversationsQuery,
} from './query';

function pad2(n: number): string {
  return String(n).padStart(2, '0');
}

/** Formats an epoch as the naive 'YYYY-MM-DD HH:MM:SS' Bangkok wall-clock string our SQL's DATE_FORMAT produces. */
function toBangkokString(epochMs: number): string {
  const shifted = new Date(epochMs + 7 * 60 * 60 * 1000);
  return `${shifted.getUTCFullYear()}-${pad2(shifted.getUTCMonth() + 1)}-${pad2(shifted.getUTCDate())} ${pad2(shifted.getUTCHours())}:${pad2(shifted.getUTCMinutes())}:${pad2(shifted.getUTCSeconds())}`;
}

function mainQuery(queries: RecordedQuery[]): RecordedQuery | undefined {
  return queries.find((q) => q.sql.includes('LEFT JOIN conversation_assignments'));
}

// ---------------------------------------------------------------------------
// parseConversationsQuery
// ---------------------------------------------------------------------------

describe('parseConversationsQuery', () => {
  it('defaults: since=0, cursor=null, limit=200, search=null, filters={}', () => {
    const parsed = parseConversationsQuery(new URLSearchParams());
    expect(parsed).toEqual({ since: 0, cursor: null, limit: 200, search: null, filters: {} });
  });

  it.each([
    ['0', 200],
    ['-5', 200],
    ['501', 200],
    ['abc', 200],
    ['', 200],
  ])('limit=%s falls back to 200 (matches api/inbox-v2.php lines 2739-2741 exactly, not clamped-to-nearest)', (raw, expected) => {
    expect(parseConversationsQuery(new URLSearchParams({ limit: raw })).limit).toBe(expected);
  });

  it.each([
    ['1', 1],
    ['500', 500],
    ['250', 250],
  ])('limit=%s within [1,500] passes through unchanged', (raw, expected) => {
    expect(parseConversationsQuery(new URLSearchParams({ limit: raw })).limit).toBe(expected);
  });

  it('parses since as a PHP (int)-cast unix timestamp', () => {
    expect(parseConversationsQuery(new URLSearchParams({ since: '1700000000' })).since).toBe(1700000000);
    expect(parseConversationsQuery(new URLSearchParams({ since: 'nope' })).since).toBe(0);
  });

  it('passes cursor through raw (untrimmed) — trimming/emptiness is handled at query-build time', () => {
    expect(parseConversationsQuery(new URLSearchParams({ cursor: '2026-07-01 00:00:00' })).cursor).toBe('2026-07-01 00:00:00');
  });

  it('trims search', () => {
    expect(parseConversationsQuery(new URLSearchParams({ search: '  somsri  ' })).search).toBe('somsri');
  });

  it('builds filters in insertion order chatStatus, unreadOnly, tagId, assigneeId, platform', () => {
    const parsed = parseConversationsQuery(
      new URLSearchParams({ chatStatus: 'pending', unreadOnly: 'true', tagId: '7', assigneeId: 'unassigned', platform: 'facebook' })
    );
    expect(Object.keys(parsed.filters)).toEqual(['chatStatus', 'unreadOnly', 'tagId', 'assigneeId', 'platform']);
    expect(parsed.filters).toEqual({ chatStatus: 'pending', unreadOnly: true, tagId: 7, assigneeId: 'unassigned', platform: 'facebook' });
  });

  it('treats chatStatus/tagId/assigneeId/platform of literal "0" as not-set (PHP empty() quirk)', () => {
    const parsed = parseConversationsQuery(new URLSearchParams({ chatStatus: '0', tagId: '0', assigneeId: '0' }));
    expect(parsed.filters).toEqual({});
  });

  it('unreadOnly is only true for the exact string "true"', () => {
    expect(parseConversationsQuery(new URLSearchParams({ unreadOnly: '1' })).filters.unreadOnly).toBeUndefined();
    expect(parseConversationsQuery(new URLSearchParams({ unreadOnly: 'false' })).filters.unreadOnly).toBeUndefined();
    expect(parseConversationsQuery(new URLSearchParams({ unreadOnly: 'true' })).filters.unreadOnly).toBe(true);
  });

  it('rejects an invalid platform value (not in line/facebook/tiktok)', () => {
    expect(parseConversationsQuery(new URLSearchParams({ platform: 'whatsapp' })).filters.platform).toBeUndefined();
  });
});

// ---------------------------------------------------------------------------
// fetchConversationsPage — WHERE-clause branch coverage (uncapped, layout.tsx's path)
// ---------------------------------------------------------------------------

describe('fetchConversationsPage WHERE clause per filter/search/cursor branch', () => {
  it('default (line platform): scopes by u.line_account_id and requires a message to exist', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await fetchConversationsPage(db, 42, { limit: 5 });
    const q = mainQuery(queries)!;
    expect(q.sql).toContain('u.line_account_id = ?');
    expect(q.sql).toContain('EXISTS (SELECT 1 FROM messages WHERE user_id = u.id)');
    expect(q.params[0]).toBe(42);
  });

  it('platform=facebook: scopes by literal u.platform (no account id bound)', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await fetchConversationsPage(db, 42, { limit: 5, filters: { platform: 'facebook' } });
    const q = mainQuery(queries)!;
    expect(q.sql).toContain("u.platform = 'facebook'");
    expect(q.params).not.toContain(42);
  });

  it('platform=tiktok: scopes by literal u.platform (no account id bound)', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await fetchConversationsPage(db, 42, { limit: 5, filters: { platform: 'tiktok' } });
    const q = mainQuery(queries)!;
    expect(q.sql).toContain("u.platform = 'tiktok'");
    expect(q.params).not.toContain(42);
  });

  it('search: LIKEs display_name and message content', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await fetchConversationsPage(db, 1, { limit: 5, search: 'somsri' });
    const q = mainQuery(queries)!;
    expect(q.sql).toContain('COALESCE(u.custom_display_name, u.display_name) LIKE ?');
    expect(q.sql).toContain('m_search.content LIKE ?');
    expect(q.params.filter((p) => p === '%somsri%')).toHaveLength(2);
  });

  it('blank/whitespace search applies no filter', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await fetchConversationsPage(db, 1, { limit: 5, search: '   ' });
    const q = mainQuery(queries)!;
    expect(q.sql).not.toContain('LIKE ?');
  });

  it('chatStatus filter', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await fetchConversationsPage(db, 1, { limit: 5, filters: { chatStatus: 'pending' } });
    const q = mainQuery(queries)!;
    expect(q.sql).toContain('u.chat_status = ?');
    expect(q.params).toContain('pending');
  });

  it('unreadOnly filter', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await fetchConversationsPage(db, 1, { limit: 5, filters: { unreadOnly: true } });
    const q = mainQuery(queries)!;
    expect(q.sql).toContain("m_unread.direction = 'incoming'");
    expect(q.sql).toContain('m_unread.is_read = 0');
  });

  it('tagId filter', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await fetchConversationsPage(db, 1, { limit: 5, filters: { tagId: 9 } });
    const q = mainQuery(queries)!;
    expect(q.sql).toContain('uta.tag_id = ?');
    expect(q.params).toContain(9);
  });

  it('assigneeId=unassigned filter: NOT EXISTS, no admin_id param', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await fetchConversationsPage(db, 1, { limit: 5, filters: { assigneeId: 'unassigned' } });
    const q = mainQuery(queries)!;
    expect(q.sql).toContain('NOT EXISTS (');
    expect(q.sql).toContain('cma.status = \'active\'');
    expect(q.sql).not.toContain('cma.admin_id = ?');
  });

  it('assigneeId=<id> filter: EXISTS with admin_id bound', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await fetchConversationsPage(db, 1, { limit: 5, filters: { assigneeId: '17' } });
    const q = mainQuery(queries)!;
    expect(q.sql).toContain('cma.admin_id = ?');
    expect(q.params).toContain(17);
  });

  it('since filter: delta cutoff via FROM_UNIXTIME', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await fetchConversationsPage(db, 1, { limit: 5, since: 1700000000 });
    const q = mainQuery(queries)!;
    expect(q.sql).toContain('> FROM_UNIXTIME(?)');
    expect(q.params).toContain(1700000000);
  });

  it('cursor filter: strictly-less-than bound cursor value', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await fetchConversationsPage(db, 1, { limit: 5, cursor: '2026-07-01 00:00:00' });
    const q = mainQuery(queries)!;
    expect(q.sql).toContain(') < ?');
    expect(q.params).toContain('2026-07-01 00:00:00');
  });

  it('a blank cursor ("" ) applies no cursor filter (matches PHP trim($cursor) !== \'\')', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await fetchConversationsPage(db, 1, { limit: 5, cursor: '' });
    const q = mainQuery(queries)!;
    expect(q.params).not.toContain('');
  });

  it('LIMIT is bound to limit+1 (fetch one extra to detect has_more)', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await fetchConversationsPage(db, 1, { limit: 5 });
    const q = mainQuery(queries)!;
    expect(q.params[q.params.length - 1]).toBe(6);
  });

  it('combines multiple filters with AND', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await fetchConversationsPage(db, 1, { limit: 5, search: 'a', filters: { chatStatus: 'pending', tagId: 2 } });
    const q = mainQuery(queries)!;
    expect(q.sql).toContain('LIKE ?');
    expect(q.sql).toContain('u.chat_status = ?');
    expect(q.sql).toContain('uta.tag_id = ?');
  });
});

// ---------------------------------------------------------------------------
// has_more / next_cursor + row shape assembly
// ---------------------------------------------------------------------------

describe('fetchConversationsPage result shape', () => {
  function rawRow(id: number, lastMessageAt: string) {
    return {
      id,
      display_name: `User ${id}`,
      picture_url: null,
      chat_status: null,
      platform: 'line',
      platform_user_id: null,
      last_message_at: lastMessageAt,
      assigned_to: null,
      assignment_status: null,
    };
  }

  it('has_more=false and next_cursor=null when fewer than limit+1 rows come back', async () => {
    const { db } = makeFakeTenantDb((sqlText) => (sqlText.includes('LEFT JOIN conversation_assignments') ? [rawRow(1, '2026-07-10 10:00:00')] : []));
    const result = await fetchConversationsPage(db, 1, { limit: 5 });
    expect(result.has_more).toBe(false);
    expect(result.next_cursor).toBeNull();
    expect(result.count).toBe(1);
  });

  it('has_more=true, pops the extra row, and next_cursor is the last KEPT row\'s last_message_at', async () => {
    const rows = [rawRow(1, '2026-07-10 12:00:00'), rawRow(2, '2026-07-10 11:00:00'), rawRow(3, '2026-07-10 10:00:00')];
    const { db } = makeFakeTenantDb((sqlText) => (sqlText.includes('LEFT JOIN conversation_assignments') ? rows : []));
    const result = await fetchConversationsPage(db, 1, { limit: 2 });
    expect(result.has_more).toBe(true);
    expect(result.count).toBe(2);
    expect(result.conversations.map((c) => c.id)).toEqual([1, 2]);
    expect(result.next_cursor).toBe('2026-07-10 11:00:00');
  });

  it('merges batch-enrichment maps onto each row with the correct field order/keys', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('LEFT JOIN conversation_assignments')) {
        return [rawRow(5, '2026-07-10 09:00:00')];
      }
      if (sqlText.includes('MAX(id) as max_id')) {
        return [{ user_id: 5, preview: 'hello', type: 'text' }];
      }
      if (sqlText.includes('is_read = 0')) {
        return [{ user_id: 5, unread: 3 }];
      }
      if (sqlText.includes('user_tags ut')) {
        return [{ user_id: 5, id: 1, name: 'VIP', color: '#fff' }];
      }
      if (sqlText.includes('conversation_multi_assignees')) {
        return [{ user_id: 5, admin_id: 9 }];
      }
      return [];
    });
    const result = await fetchConversationsPage(db, 1, { limit: 5 });
    expect(result.conversations[0]).toEqual({
      id: 5,
      display_name: 'User 5',
      picture_url: null,
      chat_status: null,
      platform: 'line',
      platform_user_id: null,
      last_message_at: '2026-07-10 09:00:00',
      assigned_to: null,
      assignment_status: null,
      unread_count: 3,
      last_message_preview: 'hello',
      last_message_type: 'text',
      tags: [{ id: 1, name: 'VIP', color: '#fff' }],
      assignees: [9],
    });
    expect(Object.keys(result.conversations[0])).toEqual([
      'id',
      'display_name',
      'picture_url',
      'chat_status',
      'platform',
      'platform_user_id',
      'last_message_at',
      'assigned_to',
      'assignment_status',
      'unread_count',
      'last_message_preview',
      'last_message_type',
      'tags',
      'assignees',
    ]);
  });

  it('rows with no enrichment data default to unread_count=0, null preview/type, empty tags/assignees', async () => {
    const { db } = makeFakeTenantDb((sqlText) => (sqlText.includes('LEFT JOIN conversation_assignments') ? [rawRow(1, '2026-07-10 09:00:00')] : []));
    const result = await fetchConversationsPage(db, 1, { limit: 5 });
    expect(result.conversations[0]).toMatchObject({
      unread_count: 0,
      last_message_preview: null,
      last_message_type: null,
      tags: [],
      assignees: [],
    });
  });
});

// ---------------------------------------------------------------------------
// getConversationsDelta — the PHP-faithful internal min(100,...) re-cap quirk
// ---------------------------------------------------------------------------

describe('getConversationsDelta internal limit re-cap (InboxService.php line 278 quirk)', () => {
  it.each([
    [200, 101],
    [500, 101],
    [150, 101],
    [50, 51],
    [1, 2],
  ])('requested limit=%i re-caps to min(100,limit), binding LIMIT %i', async (requested, expectedLimitParam) => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await getConversationsDelta(db, 1, { limit: requested });
    const q = mainQuery(queries)!;
    expect(q.params[q.params.length - 1]).toBe(expectedLimitParam);
  });

  it('defaults to 50 (then capped to 51) when no limit is given, matching InboxService\'s own default param', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await getConversationsDelta(db, 1, {});
    const q = mainQuery(queries)!;
    expect(q.params[q.params.length - 1]).toBe(51);
  });

  it(`fetchConversationsPage (layout.tsx's uncapped path) with limit=${CONVERSATIONS_LIMIT_DEFAULT} is NOT re-capped`, async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    await fetchConversationsPage(db, 1, { limit: CONVERSATIONS_LIMIT_DEFAULT });
    const q = mainQuery(queries)!;
    expect(q.params[q.params.length - 1]).toBe(CONVERSATIONS_LIMIT_DEFAULT + 1);
  });
});

// ---------------------------------------------------------------------------
// Batch helpers — empty-input short circuit + IN-list + mapping
// ---------------------------------------------------------------------------

describe('batch enrichment helpers', () => {
  it('every helper short-circuits to {} for an empty id list (no query executed)', async () => {
    const { db, queries } = makeFakeTenantDb(() => []);
    expect(await getLastMessageMetaBatch(db, [])).toEqual({});
    expect(await getUnreadCountsBatch(db, [])).toEqual({});
    expect(await getUserTagsBatch(db, [])).toEqual({});
    expect(await getAssignedAdminIdsBatch(db, [])).toEqual({});
    expect(queries).toHaveLength(0);
  });

  it('getLastMessageMetaBatch dedupes ids and maps user_id -> {preview, type}', async () => {
    const { db, queries } = makeFakeTenantDb(() => [{ user_id: 1, preview: 'hi', type: 'text' }]);
    const result = await getLastMessageMetaBatch(db, [1, 1, 2]);
    expect(result).toEqual({ 1: { preview: 'hi', type: 'text' } });
    expect(queries[0]?.params).toEqual([1, 2]);
  });

  it('getUnreadCountsBatch maps user_id -> numeric count', async () => {
    const { db } = makeFakeTenantDb(() => [{ user_id: 1, unread: '4' }]);
    expect(await getUnreadCountsBatch(db, [1])).toEqual({ 1: 4 });
  });

  it('getUserTagsBatch groups multiple tags per user, dropping the user_id key from each tag object', async () => {
    const { db } = makeFakeTenantDb(() => [
      { user_id: 1, id: 10, name: 'VIP', color: '#f00' },
      { user_id: 1, id: 11, name: 'New', color: null },
    ]);
    expect(await getUserTagsBatch(db, [1])).toEqual({
      1: [
        { id: 10, name: 'VIP', color: '#f00' },
        { id: 11, name: 'New', color: null },
      ],
    });
  });

  it('getAssignedAdminIdsBatch groups admin ids per user', async () => {
    const { db } = makeFakeTenantDb(() => [
      { user_id: 1, admin_id: 5 },
      { user_id: 1, admin_id: 6 },
    ]);
    expect(await getAssignedAdminIdsBatch(db, [1])).toEqual({ 1: [5, 6] });
  });
});

// ---------------------------------------------------------------------------
// Cursor golden test — the acceptance-criteria "disjoint, contiguous,
// concatenation-matches-a-direct-query" property over >=210 seeded rows.
// ---------------------------------------------------------------------------

describe('cursor pagination golden test', () => {
  function makeSeed(n: number, baseEpochMs: number) {
    // n conversations 1 minute apart, strictly decreasing timestamps (id=1 newest).
    return Array.from({ length: n }, (_, i) => ({ id: i + 1, last_message_at: toBangkokString(baseEpochMs - i * 60_000) }));
  }

  function seededQueryImpl(seedSortedDesc: Array<{ id: number; last_message_at: string }>) {
    return (sqlText: string, params: unknown[]) => {
      if (!sqlText.includes('LEFT JOIN conversation_assignments')) {
        return []; // batch enrichment queries — irrelevant to cursor correctness
      }
      const limitPlusOne = Number(params[params.length - 1]);
      const hasCursor = params.length === 3;
      const cursor = hasCursor ? String(params[1]) : null;
      const filtered = cursor ? seedSortedDesc.filter((r) => r.last_message_at < cursor) : seedSortedDesc;
      return filtered.slice(0, limitPlusOne).map((r) => ({
        id: r.id,
        display_name: `User ${r.id}`,
        picture_url: null,
        chat_status: null,
        platform: 'line',
        platform_user_id: null,
        last_message_at: r.last_message_at,
        assigned_to: null,
        assignment_status: null,
      }));
    };
  }

  it('two Route-Handler pages (getConversationsDelta, limit=200) are disjoint, contiguous, and order-match a direct full query', async () => {
    const seed = makeSeed(210, Date.UTC(2026, 6, 14, 10, 0, 0));
    const seedSortedDesc = [...seed].sort((a, b) => (a.last_message_at < b.last_message_at ? 1 : -1));
    const directOrderIds = seedSortedDesc.map((r) => r.id);

    const page1db = makeFakeTenantDb(seededQueryImpl(seedSortedDesc));
    const page1 = await getConversationsDelta(page1db.db, 1, { limit: 200 });
    expect(page1.has_more).toBe(true);
    expect(page1.next_cursor).not.toBeNull();

    const page2db = makeFakeTenantDb(seededQueryImpl(seedSortedDesc));
    const page2 = await getConversationsDelta(page2db.db, 1, { limit: 200, cursor: page1.next_cursor });

    const page1Ids = page1.conversations.map((c) => c.id);
    const page2Ids = page2.conversations.map((c) => c.id);

    // Disjoint.
    expect(page1Ids.filter((id) => page2Ids.includes(id))).toEqual([]);

    // Contiguous: page 2's first row is strictly earlier than page 1's last row.
    const page1Last = page1.conversations[page1.conversations.length - 1].last_message_at!;
    const page2First = page2.conversations[0].last_message_at!;
    expect(page2First < page1Last).toBe(true);

    // Concatenation order matches the corresponding prefix of a direct DESC query.
    const concatenated = [...page1Ids, ...page2Ids];
    expect(concatenated).toEqual(directOrderIds.slice(0, concatenated.length));
  });

  it("layout.tsx's uncapped fetchConversationsPage(limit=200) returns up to 200 rows in one page (not re-capped to 100)", async () => {
    const seed = makeSeed(210, Date.UTC(2026, 6, 14, 10, 0, 0));
    const seedSortedDesc = [...seed].sort((a, b) => (a.last_message_at < b.last_message_at ? 1 : -1));
    const { db } = makeFakeTenantDb(seededQueryImpl(seedSortedDesc));

    const page = await fetchConversationsPage(db, 1, { limit: 200 });
    expect(page.conversations).toHaveLength(200);
    expect(page.has_more).toBe(true);
    expect(page.conversations.map((c) => c.id)).toEqual(seedSortedDesc.slice(0, 200).map((r) => r.id));
  });
});
