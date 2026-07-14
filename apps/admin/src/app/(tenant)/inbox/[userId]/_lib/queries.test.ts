/**
 * @jest-environment node
 */
import { makeFakeTenantDb } from '../../../users/testHelpers/fakeTenantDb';
import { getInboxThreadPageData } from './queries';

describe('getInboxThreadPageData', () => {
  it('returns null when the user id does not resolve to a row', async () => {
    const { db } = makeFakeTenantDb(() => []);
    await expect(getInboxThreadPageData(db, 999)).resolves.toBeNull();
  });

  it('assembles selectedUser + userTags + the latest-300 messages', async () => {
    const { db, queries } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM users')) {
        return [{ id: 7, picture_url: 'https://x/p.png', display_name: 'สมศรี', custom_display_name: null }];
      }
      if (sqlText.includes('FROM user_tags')) {
        return [{ id: 1, name: 'VIP', color: '#ff0000' }];
      }
      if (sqlText.includes('FROM messages') || sqlText.includes('SELECT * FROM')) {
        return [
          { id: 1, user_id: 7, direction: 'incoming', message_type: 'text', content: 'hi', is_read: 1, sent_by: null, created_at: new Date() },
        ];
      }
      return [];
    });

    const data = await getInboxThreadPageData(db, 7);

    expect(data).not.toBeNull();
    expect(data!.selectedUser.display_name).toBe('สมศรี');
    expect(data!.userTags).toEqual([{ id: 1, name: 'VIP', color: '#ff0000' }]);
    expect(data!.messages).toHaveLength(1);

    const tagQuery = queries.find((q) => q.sql.includes('FROM user_tags'));
    expect(tagQuery?.params).toEqual([7]);
  });

  it('an existing user with no tags/messages returns empty arrays, not an error', async () => {
    const { db } = makeFakeTenantDb((sqlText) => {
      if (sqlText.includes('FROM users')) {
        return [{ id: 7, picture_url: null, display_name: null, custom_display_name: null }];
      }
      return [];
    });
    const data = await getInboxThreadPageData(db, 7);
    expect(data).toEqual({
      selectedUser: { id: 7, picture_url: null, display_name: null, custom_display_name: null },
      userTags: [],
      messages: [],
    });
  });
});
