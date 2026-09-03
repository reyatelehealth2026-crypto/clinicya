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

import {
  createBroadcastAction,
  deleteCampaignAction,
  sendProductBroadcastAction,
} from './products-actions';
import { CREATE_BROADCAST_ERRORS } from './products-errors';

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

describe('createBroadcastAction — products.php:79-133 validation (exact Thai strings, zero DB writes)', () => {
  it('empty name -> redirects with the exact Thai error in ?error=, requireTenantPageContext / db never touched', async () => {
    await expect(createBroadcastAction(formData({ name: '', 'products[]': ['1'] }))).rejects.toThrow(
      `REDIRECT:/broadcast?tab=products&error=${encodeURIComponent(CREATE_BROADCAST_ERRORS.emptyName)}`
    );
    expect(CREATE_BROADCAST_ERRORS.emptyName).toBe('กรุณากรอกชื่อ Broadcast');
    expect(mockRequireTenantPageContext).not.toHaveBeenCalled();
  });

  it('whitespace-only name is trimmed to empty -> same redirect error', async () => {
    await expect(createBroadcastAction(formData({ name: '   ', 'products[]': ['1'] }))).rejects.toThrow(
      `REDIRECT:/broadcast?tab=products&error=${encodeURIComponent(CREATE_BROADCAST_ERRORS.emptyName)}`
    );
  });

  it('no products selected -> redirects with the exact Thai error, zero DB writes', async () => {
    await expect(createBroadcastAction(formData({ name: 'แคมเปญ A' }))).rejects.toThrow(
      `REDIRECT:/broadcast?tab=products&error=${encodeURIComponent(CREATE_BROADCAST_ERRORS.noProducts)}`
    );
    expect(CREATE_BROADCAST_ERRORS.noProducts).toBe('กรุณาเลือกสินค้าอย่างน้อย 1 รายการ');
    expect(mockRequireTenantPageContext).not.toHaveBeenCalled();
  });

  it('more than 10 products selected -> redirects with the exact Thai error, zero DB writes', async () => {
    const elevenProducts = Array.from({ length: 11 }, (_, i) => String(i + 1));
    await expect(
      createBroadcastAction(formData({ name: 'แคมเปญ A', 'products[]': elevenProducts }))
    ).rejects.toThrow(`REDIRECT:/broadcast?tab=products&error=${encodeURIComponent(CREATE_BROADCAST_ERRORS.tooManyProducts)}`);
    expect(CREATE_BROADCAST_ERRORS.tooManyProducts).toBe('เลือกสินค้าได้สูงสุด 10 รายการ');
    expect(mockRequireTenantPageContext).not.toHaveBeenCalled();
  });

  it('exactly 10 products is allowed (boundary — only >10 is rejected)', async () => {
    const tenProducts = Array.from({ length: 10 }, (_, i) => String(i + 1));
    const { queries } = wireFakeDb((sqlText) => {
      if (sqlText.includes('FROM business_items')) return [{ id: 1, name: 'P', price: '10', sale_price: null, image_url: null }];
      return [];
    });
    await expect(createBroadcastAction(formData({ name: 'แคมเปญ A', 'products[]': tenProducts }))).rejects.toThrow(
      /REDIRECT:/
    );
    expect(queries.some((q) => q.sql.includes('INSERT INTO broadcast_campaigns'))).toBe(true);
  });

  it('success path INSERTs broadcast_campaigns then broadcast_items with the EXACT products.php:121 column list (no line_account_id)', async () => {
    const { queries } = wireFakeDb((sqlText) => {
      if (sqlText.includes('FROM business_items')) {
        return [{ id: 1, name: 'ยาแก้ปวด', price: '50.00', sale_price: '40.00', image_url: 'https://x/p.jpg' }];
      }
      if (sqlText.includes('SELECT id FROM user_tags')) return []; // no existing tag -> INSERT one
      return [];
    });
    await expect(
      createBroadcastAction(formData({ name: 'โปรใหม่', auto_tag_enabled: '1', 'products[]': ['1'] }))
    ).rejects.toThrow('REDIRECT:/broadcast?tab=products&success=created&id=');

    const itemInsert = queries.find((q) => q.sql.includes('INSERT INTO broadcast_items'));
    expect(itemInsert?.sql).toMatch(
      /INSERT INTO broadcast_items \(broadcast_id, product_id, item_name, item_image, item_price, postback_data, tag_id, sort_order\)/
    );
    expect(itemInsert?.sql).not.toContain('line_account_id');
  });

  it('a selected product id that does not resolve (deleted/inactive) is silently skipped, matching PHP\'s `if ($product)` guard', async () => {
    const { queries } = wireFakeDb((sqlText) => {
      if (sqlText.includes('FROM business_items')) return []; // getItem() returns null
      return [];
    });
    await expect(createBroadcastAction(formData({ name: 'โปรใหม่', 'products[]': ['999'] }))).rejects.toThrow(
      /REDIRECT:/
    );
    expect(queries.some((q) => q.sql.includes('INSERT INTO broadcast_items'))).toBe(false);
  });
});

describe('sendProductBroadcastAction — products.php:141-197 (its own inline targeting, throws Thai errors)', () => {
  it("returns the exact Thai 'ไม่พบ Campaign' error when the campaign id doesn't exist", async () => {
    const { db } = wireFakeDb(() => []);
    const result = await sendProductBroadcastAction(formData({ campaign_id: '999', target_type: 'all' }));
    expect(result).toEqual({ error: 'ส่ง Broadcast ไม่สำเร็จ: ไม่พบ Campaign' });
    expect(mockBroadcastMessage).not.toHaveBeenCalled();
    expect(mockRedirect).not.toHaveBeenCalled();
    void db;
  });

  it("returns the exact Thai 'ไม่มีสินค้าใน Campaign' error when the campaign has zero items", async () => {
    wireFakeDb((sqlText) => {
      if (sqlText.includes('FROM broadcast_campaigns')) return [{ id: 5, name: 'แคมเปญ A' }];
      if (sqlText.includes('FROM broadcast_items')) return [];
      return [];
    });
    const result = await sendProductBroadcastAction(formData({ campaign_id: '5', target_type: 'all' }));
    expect(result).toEqual({ error: 'ส่ง Broadcast ไม่สำเร็จ: ไม่มีสินค้าใน Campaign' });
    expect(mockBroadcastMessage).not.toHaveBeenCalled();
  });

  it("target_type='all': builds a Flex carousel, calls broadcastMessage once, UPDATEs status=sent, sentCount is the -1 sentinel, redirects with count=-1", async () => {
    mockBroadcastMessage.mockResolvedValue({ code: 200, body: {} });
    const { queries } = wireFakeDb((sqlText) => {
      if (sqlText.includes('FROM broadcast_campaigns')) return [{ id: 5, name: 'แคมเปญ A' }];
      if (sqlText.includes('FROM broadcast_items')) {
        return [{ item_name: 'สินค้า A', item_image: 'https://x/a.jpg', item_price: '99.00', postback_data: 'pb1' }];
      }
      if (sqlText.includes('FROM line_accounts')) return [{ channel_access_token: 'tok-abc' }];
      return [];
    });

    await expect(sendProductBroadcastAction(formData({ campaign_id: '5', target_type: 'all' }))).rejects.toThrow(
      'REDIRECT:/broadcast?tab=products&success=sent&count=-1'
    );

    expect(mockBroadcastMessage).toHaveBeenCalledTimes(1);
    const [sentMessages, options] = mockBroadcastMessage.mock.calls[0] as [unknown[], { channelAccessToken: string }];
    expect(options.channelAccessToken).toBe('tok-abc');
    expect(sentMessages[0]).toMatchObject({ type: 'flex', altText: '📦 แคมเปญ A' });

    const update = queries.find((q) => q.sql.includes('UPDATE broadcast_campaigns'));
    expect(update?.sql).toContain("status = 'sent'");
  });

  it("target_type='tags': DISTINCT-scoped query, chunked multicastMessage, real summed sentCount", async () => {
    mockMulticastMessage.mockResolvedValue({ code: 200, body: {} });
    wireFakeDb((sqlText) => {
      if (sqlText.includes('FROM broadcast_campaigns')) return [{ id: 5, name: 'แคมเปญ A' }];
      if (sqlText.includes('FROM broadcast_items')) {
        return [{ item_name: 'สินค้า A', item_image: null, item_price: '10', postback_data: 'pb1' }];
      }
      if (sqlText.includes('FROM line_accounts')) return [{ channel_access_token: 'tok' }];
      if (sqlText.includes('user_tag_assignments')) return [{ line_user_id: 'U1' }, { line_user_id: 'U2' }];
      return [];
    });

    await expect(
      sendProductBroadcastAction(formData({ campaign_id: '5', target_type: 'tags', 'target_tags[]': ['1', '2'] }))
    ).rejects.toThrow('REDIRECT:/broadcast?tab=products&success=sent&count=2');
    expect(mockMulticastMessage).toHaveBeenCalledTimes(1);
  });
});

describe('deleteCampaignAction — products.php:199-209 (two-table transactional delete)', () => {
  it('DELETEs broadcast_items THEN broadcast_campaigns, in that order, then redirects', async () => {
    const { queries } = wireFakeDb();
    await expect(deleteCampaignAction(formData({ campaign_id: '7' }))).rejects.toThrow(
      'REDIRECT:/broadcast?tab=products&success=deleted'
    );
    const deletes = queries.filter((q) => q.sql.startsWith('DELETE'));
    expect(deletes).toHaveLength(2);
    expect(deletes[0]?.sql).toContain('DELETE FROM broadcast_items');
    expect(deletes[0]?.params).toEqual([7]);
    expect(deletes[1]?.sql).toContain('DELETE FROM broadcast_campaigns');
    expect(deletes[1]?.params).toEqual([7]);
  });

  it('a DB error rolls back and redirects with a Thai error in ?error= (not the success redirect)', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('connection lost');
    });
    mockRequireTenantPageContext.mockResolvedValue({ db, session: fakeSession() });
    await expect(deleteCampaignAction(formData({ campaign_id: '7' }))).rejects.toThrow(
      `REDIRECT:/broadcast?tab=products&error=${encodeURIComponent('ลบไม่สำเร็จ: connection lost')}`
    );
  });
});
