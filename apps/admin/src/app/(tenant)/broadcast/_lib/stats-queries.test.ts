import { makeFakeTenantDb } from '../../users/testHelpers/fakeTenantDb';
import {
  computeCtr,
  formatClickDate,
  formatPickerDate,
  getCampaignById,
  getCampaignItems,
  getCampaignPicker,
  getOverallStats,
  getRecentClicks,
  pickerEntryHref,
} from './stats-queries';

describe('getCampaignById', () => {
  it('returns null when no row matches (stats.php !$campaign branch)', async () => {
    const { db } = makeFakeTenantDb(() => []);
    await expect(getCampaignById(db, 999)).resolves.toBeNull();
  });

  it('maps a found row and hardcodes totalSent to 0 (confirmed dead column)', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM broadcast_campaigns')) {
        return [{ id: 5, name: 'โปรเดือนนี้', status: 'sent', sent_count: 120 }];
      }
      return [];
    });

    const result = await getCampaignById(db, 5);

    expect(result).toEqual({ id: 5, name: 'โปรเดือนนี้', status: 'sent', sentCount: 120, totalSent: 0 });
    const q = queries.find((x) => x.sql.includes('FROM broadcast_campaigns'));
    expect(q?.params).toContain(5);
  });
});

describe('getCampaignItems', () => {
  it('orders by click_count DESC', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM broadcast_items')) {
        return [
          { id: 1, item_name: 'ยาลดไข้', item_image: null, click_count: 10 },
          { id: 2, item_name: 'วิตามินซี', item_image: 'https://x/img.jpg', click_count: 3 },
        ];
      }
      return [];
    });

    const result = await getCampaignItems(db, 5);

    expect(queries[0]?.sql).toContain('ORDER BY click_count DESC');
    expect(result).toEqual([
      { id: 1, itemName: 'ยาลดไข้', itemImage: null, clickCount: 10 },
      { id: 2, itemName: 'วิตามินซี', itemImage: 'https://x/img.jpg', clickCount: 3 },
    ]);
  });
});

describe('getRecentClicks', () => {
  it('maps a joined row, LIMIT 50, ORDER BY clicked_at DESC', async () => {
    const clickedAt = new Date('2026-08-14T03:30:00Z');
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM broadcast_clicks')) {
        return [
          {
            id: 1,
            clicked_at: clickedAt,
            tag_assigned: 1,
            display_name: 'สมชาย',
            picture_url: 'https://x/pic.jpg',
            item_name: 'ยาลดไข้',
          },
        ];
      }
      return [];
    });

    const result = await getRecentClicks(db, 5);

    expect(queries[0]?.sql).toContain('LIMIT 50');
    expect(queries[0]?.sql).toContain('ORDER BY bc.clicked_at DESC');
    expect(result).toEqual([
      {
        id: 1,
        displayName: 'สมชาย',
        pictureUrl: 'https://x/pic.jpg',
        itemName: 'ยาลดไข้',
        clickedAt,
        tagAssigned: true,
      },
    ]);
  });

  it('swallows a thrown error and returns [] (matches PHP try/catch(Exception){})', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error("Table 'users' doesn't exist");
    });
    await expect(getRecentClicks(db, 5)).resolves.toEqual([]);
  });
});

describe('getCampaignPicker', () => {
  it('returns the UNION ALL rows with their kind discriminator on the happy path', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('UNION ALL')) {
        return [
          { id: 1, name: 'แคมเปญ A', status: 'sent', created_at: new Date('2026-08-01'), sent_count: 50, kind: 'campaign' },
          { id: 2, name: 'ส่งด่วน B', status: 'sent', created_at: new Date('2026-08-02'), sent_count: 10, kind: 'quick' },
        ];
      }
      return [];
    });

    const result = await getCampaignPicker(db, 7);

    expect(queries[0]?.sql).toContain('UNION ALL');
    expect(queries[0]?.sql).toContain('FROM broadcasts');
    expect(result).toEqual([
      { id: 1, name: 'แคมเปญ A', status: 'sent', createdAt: new Date('2026-08-01'), sentCount: 50, kind: 'campaign' },
      { id: 2, name: 'ส่งด่วน B', status: 'sent', createdAt: new Date('2026-08-02'), sentCount: 10, kind: 'quick' },
    ]);
  });

  it('falls back to the broadcast_campaigns-only query when the UNION ALL throws', async () => {
    let calls = 0;
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      calls += 1;
      if (sqlText.includes('UNION ALL')) throw new Error("Table 'broadcasts' doesn't exist");
      if (sqlText.includes('FROM broadcast_campaigns')) {
        return [{ id: 1, name: 'แคมเปญ A', status: 'draft', created_at: new Date('2026-08-01'), sent_count: 0, kind: 'campaign' }];
      }
      return [];
    });

    const result = await getCampaignPicker(db, 7);

    expect(calls).toBe(2);
    expect(queries[1]?.sql).not.toContain('UNION ALL');
    expect(result).toEqual([
      { id: 1, name: 'แคมเปญ A', status: 'draft', createdAt: new Date('2026-08-01'), sentCount: 0, kind: 'campaign' },
    ]);
  });

  it('returns [] when both the primary and fallback query throw', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('boom');
    });
    await expect(getCampaignPicker(db, 7)).resolves.toEqual([]);
  });

  it("routes 'quick' rows to the send-tab history href and 'campaign' rows to the stats detail href (stats.php lines 174-179)", async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('UNION ALL')) {
        return [
          { id: 9, name: 'Campaign row', status: 'sent', created_at: new Date('2026-08-01'), sent_count: 1, kind: 'campaign' },
          { id: 11, name: 'Quick row', status: 'sent', created_at: new Date('2026-08-01'), sent_count: 1, kind: 'quick' },
        ];
      }
      return [];
    });
    const result = await getCampaignPicker(db, 1);

    expect(pickerEntryHref(result[0]!)).toBe('?tab=stats&id=9');
    expect(pickerEntryHref(result[1]!)).toBe('?tab=send');
  });
});

describe('pickerEntryHref', () => {
  it('routes campaign kind to the stats detail href', () => {
    expect(pickerEntryHref({ id: 42, kind: 'campaign' })).toBe('?tab=stats&id=42');
  });

  it('routes quick kind to the send-tab history href, ignoring id', () => {
    expect(pickerEntryHref({ id: 42, kind: 'quick' })).toBe('?tab=send');
  });
});

describe('getOverallStats', () => {
  it('combines broadcast_campaigns + broadcasts counts, and sums sent_count across both for totalSentUsers', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('SELECT COUNT(*) AS c FROM broadcast_campaigns') && sqlText.includes("status = 'sent'")) return [{ c: 3 }];
      if (sqlText.includes('SELECT COUNT(*) AS c FROM broadcast_campaigns')) return [{ c: 5 }];
      if (sqlText.includes('SELECT COUNT(*) AS c FROM broadcasts') && sqlText.includes("status = 'sent'")) return [{ c: 2 }];
      if (sqlText.includes('SELECT COUNT(*) AS c FROM broadcasts')) return [{ c: 4 }];
      if (sqlText.includes('total_sent_users')) return [{ total_sent_users: 777 }];
      if (sqlText.includes('FROM broadcast_clicks WHERE line_account_id')) return [{ c: 42 }];
      return [];
    });

    const result = await getOverallStats(db, 7);

    expect(result).toEqual({
      totalCampaigns: 9, // 5 (campaigns) + 4 (broadcasts)
      sentCampaigns: 5, // 3 (campaigns) + 2 (broadcasts)
      totalSentUsers: 777,
      totalClicks: 42,
    });
  });

  it('keeps campaign-only totals and totalSentUsers=0 when the broadcasts-table queries throw', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('SELECT COUNT(*) AS c FROM broadcast_campaigns') && sqlText.includes("status = 'sent'")) return [{ c: 3 }];
      if (sqlText.includes('SELECT COUNT(*) AS c FROM broadcast_campaigns')) return [{ c: 5 }];
      if (sqlText.includes('FROM broadcasts')) throw new Error("Table 'broadcasts' doesn't exist");
      if (sqlText.includes('FROM broadcast_clicks WHERE line_account_id')) return [{ c: 12 }];
      return [];
    });

    const result = await getOverallStats(db, 7);

    expect(result).toEqual({ totalCampaigns: 5, sentCampaigns: 3, totalSentUsers: 0, totalClicks: 12 });
  });

  it('falls back to the JOIN-based click count when broadcast_clicks.line_account_id throws', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('SELECT COUNT(*) AS c FROM broadcast_campaigns')) return [{ c: 0 }];
      if (sqlText.includes('SELECT COUNT(*) AS c FROM broadcasts')) return [{ c: 0 }];
      if (sqlText.includes('total_sent_users')) return [{ total_sent_users: 0 }];
      if (sqlText.includes('FROM broadcast_clicks WHERE line_account_id')) throw new Error('Unknown column');
      if (sqlText.includes('JOIN broadcast_campaigns bcm')) return [{ c: 8 }];
      return [];
    });

    const result = await getOverallStats(db, 7);

    expect(result.totalClicks).toBe(8);
    expect(queries.some((q) => q.sql.includes('JOIN broadcast_campaigns bcm'))).toBe(true);
  });

  it('returns all-zero defaults when the outer broadcast_campaigns counts throw', async () => {
    const { db } = makeFakeTenantDb(() => {
      throw new Error('connection lost');
    });
    await expect(getOverallStats(db, 7)).resolves.toEqual({
      totalCampaigns: 0,
      sentCampaigns: 0,
      totalClicks: 0,
      totalSentUsers: 0,
    });
  });
});

describe('computeCtr (stats.php line 236)', () => {
  it('returns 0 when totalSent is 0 (avoids division by zero)', () => {
    expect(computeCtr(10, 0)).toBe(0);
  });

  it('computes clicks/sent*100 when totalSent > 0', () => {
    expect(computeCtr(25, 200)).toBe(12.5);
  });

  it('is 0 for 0 clicks even with a positive sent count', () => {
    expect(computeCtr(0, 100)).toBe(0);
  });
});

describe('formatPickerDate / formatClickDate (Bangkok, plain Gregorian — not Buddhist era)', () => {
  it('formats as d/m/Y H:i in Asia/Bangkok', () => {
    expect(formatPickerDate(new Date('2026-08-14T03:30:00Z'))).toBe('14/08/2026 10:30');
  });

  it('formats as d/m H:i in Asia/Bangkok', () => {
    expect(formatClickDate(new Date('2026-08-14T03:30:00Z'))).toBe('14/08 10:30');
  });

  it('returns "-" for an unparsable date string', () => {
    expect(formatPickerDate('not-a-date')).toBe('-');
    expect(formatClickDate('not-a-date')).toBe('-');
  });
});
