const mockRequireTenantPageContext = jest.fn();
jest.mock('../users/_lib/session', () => ({
  requireTenantPageContext: () => mockRequireTenantPageContext(),
}));

const mockRevalidatePath = jest.fn();
jest.mock('next/cache', () => ({
  revalidatePath: (...args: unknown[]) => mockRevalidatePath(...args),
}));

const mockGiveByPhone = jest.fn();
const mockGetMemberDetail = jest.fn();
jest.mock('./_lib/pointsClaim', () => ({
  giveByPhone: (...args: unknown[]) => mockGiveByPhone(...args),
  getMemberDetail: (...args: unknown[]) => mockGetMemberDetail(...args),
}));

import { giveByPhoneAction, memberDetailAction } from './actions';

describe('giveByPhoneAction', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockRequireTenantPageContext.mockResolvedValue({ db: {}, session: { currentBotId: 7, adminUserId: 3 } });
  });

  it('short-circuits with an error when there is no current bot, without calling giveByPhone', async () => {
    mockRequireTenantPageContext.mockResolvedValue({ db: {}, session: { currentBotId: null, adminUserId: 3 } });
    const result = await giveByPhoneAction({ phone: '0812345678', name: '', userId: '', amount: '100', points: '' });
    expect(result).toEqual({ success: false, message: 'Missing line_account_id' });
    expect(mockGiveByPhone).not.toHaveBeenCalled();
  });

  it('parses form-string fields into the typed GiveByPhoneInput and forwards lineAccountId/adminUserId from the session', async () => {
    mockGiveByPhone.mockResolvedValue({ success: true, message: 'ok' });
    await giveByPhoneAction({ phone: '081-234-5678', name: 'สมศรี', userId: '5', amount: '250', points: '' });
    expect(mockGiveByPhone).toHaveBeenCalledWith(
      {},
      { lineAccountId: 7, adminUserId: 3, phone: '081-234-5678', name: 'สมศรี', userId: 5, amount: 250, points: 0, paymentMethod: '' }
    );
  });

  it('revalidates /loyalty-members on success, matching the PHP client\'s location.reload()', async () => {
    mockGiveByPhone.mockResolvedValue({ success: true, message: 'ok' });
    await giveByPhoneAction({ phone: '0812345678', name: '', userId: '', amount: '100', points: '' });
    expect(mockRevalidatePath).toHaveBeenCalledWith('/loyalty-members');
  });

  it('does NOT revalidate on failure', async () => {
    mockGiveByPhone.mockResolvedValue({ success: false, message: 'error' });
    await giveByPhoneAction({ phone: '0812345678', name: '', userId: '', amount: '100', points: '' });
    expect(mockRevalidatePath).not.toHaveBeenCalled();
  });
});

describe('memberDetailAction', () => {
  it('reads the current bot from session and delegates to getMemberDetail', async () => {
    mockRequireTenantPageContext.mockResolvedValue({ db: {}, session: { currentBotId: 7, adminUserId: 3 } });
    mockGetMemberDetail.mockResolvedValue({ success: true });
    const result = await memberDetailAction(42);
    expect(mockGetMemberDetail).toHaveBeenCalledWith({}, 7, 42);
    expect(result).toEqual({ success: true });
  });

  it('passes 0 as lineAccountId when there is no current bot', async () => {
    mockRequireTenantPageContext.mockResolvedValue({ db: {}, session: { currentBotId: null, adminUserId: 3 } });
    mockGetMemberDetail.mockResolvedValue({ success: false });
    await memberDetailAction(42);
    expect(mockGetMemberDetail).toHaveBeenCalledWith({}, 0, 42);
  });
});
