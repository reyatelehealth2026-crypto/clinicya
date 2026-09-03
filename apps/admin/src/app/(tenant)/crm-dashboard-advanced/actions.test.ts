const mockRequireTenantPageContext = jest.fn();
jest.mock('../users/_lib/session', () => ({
  requireTenantPageContext: () => mockRequireTenantPageContext(),
}));

const mockRevalidatePath = jest.fn();
jest.mock('next/cache', () => ({
  revalidatePath: (...args: unknown[]) => mockRevalidatePath(...args),
}));

const mockMoveDeal = jest.fn();
const mockCreateDeal = jest.fn();
const mockUpdateDeal = jest.fn();
const mockDeleteDeal = jest.fn();
const mockCreateTicket = jest.fn();
const mockUpdateTicket = jest.fn();
const mockAddTicketInteraction = jest.fn();
jest.mock('./_lib/mutations', () => ({
  moveDeal: (...args: unknown[]) => mockMoveDeal(...args),
  createDeal: (...args: unknown[]) => mockCreateDeal(...args),
  updateDeal: (...args: unknown[]) => mockUpdateDeal(...args),
  deleteDeal: (...args: unknown[]) => mockDeleteDeal(...args),
  createTicket: (...args: unknown[]) => mockCreateTicket(...args),
  updateTicket: (...args: unknown[]) => mockUpdateTicket(...args),
  addTicketInteraction: (...args: unknown[]) => mockAddTicketInteraction(...args),
}));

const mockGetCustomer360 = jest.fn();
jest.mock('./queries', () => ({
  getCustomer360: (...args: unknown[]) => mockGetCustomer360(...args),
}));

import {
  moveDealAction,
  createDealAction,
  updateDealAction,
  deleteDealAction,
  createTicketAction,
  updateTicketAction,
  addTicketInteractionAction,
  getCustomer360Action,
} from './actions';

const FAKE_DB = { fake: true };

beforeEach(() => {
  jest.clearAllMocks();
  mockRequireTenantPageContext.mockResolvedValue({ db: FAKE_DB, session: { currentBotId: 7, tenantId: 1, adminUserId: 3 } });
});

describe('moveDealAction', () => {
  it('delegates to moveDeal with the resolved db and revalidates on success', async () => {
    mockMoveDeal.mockResolvedValue({ success: true, message: 'Deal moved successfully' });
    const result = await moveDealAction(5, 'qualified');
    expect(mockMoveDeal).toHaveBeenCalledWith(FAKE_DB, 5, 'qualified');
    expect(result).toEqual({ success: true, message: 'Deal moved successfully' });
    expect(mockRevalidatePath).toHaveBeenCalledWith('/crm-dashboard-advanced');
  });

  it('does NOT revalidate on failure', async () => {
    mockMoveDeal.mockResolvedValue({ success: false, error: 'Invalid stage' });
    await moveDealAction(5, 'bogus');
    expect(mockRevalidatePath).not.toHaveBeenCalled();
  });
});

describe('createDealAction', () => {
  it('delegates and revalidates on success', async () => {
    mockCreateDeal.mockResolvedValue({ success: true, deal_id: 9 });
    const input = { customer_id: 1, title: 'Deal', value: 100 };
    const result = await createDealAction(input);
    expect(mockCreateDeal).toHaveBeenCalledWith(FAKE_DB, input);
    expect(result).toEqual({ success: true, deal_id: 9 });
    expect(mockRevalidatePath).toHaveBeenCalledWith('/crm-dashboard-advanced');
  });
});

describe('updateDealAction', () => {
  it('delegates and revalidates on success', async () => {
    mockUpdateDeal.mockResolvedValue({ success: true });
    await updateDealAction(3, { title: 'New' });
    expect(mockUpdateDeal).toHaveBeenCalledWith(FAKE_DB, 3, { title: 'New' });
    expect(mockRevalidatePath).toHaveBeenCalledWith('/crm-dashboard-advanced');
  });
});

describe('deleteDealAction', () => {
  it('delegates and revalidates on success', async () => {
    mockDeleteDeal.mockResolvedValue({ success: true });
    await deleteDealAction(3);
    expect(mockDeleteDeal).toHaveBeenCalledWith(FAKE_DB, 3);
    expect(mockRevalidatePath).toHaveBeenCalledWith('/crm-dashboard-advanced');
  });
});

describe('createTicketAction', () => {
  it('delegates and revalidates on success', async () => {
    mockCreateTicket.mockResolvedValue({ success: true, ticket_id: 4 });
    const input = { customer_id: 1, subject: 'Help' };
    const result = await createTicketAction(input);
    expect(mockCreateTicket).toHaveBeenCalledWith(FAKE_DB, input);
    expect(result).toEqual({ success: true, ticket_id: 4 });
    expect(mockRevalidatePath).toHaveBeenCalledWith('/crm-dashboard-advanced');
  });

  it('does NOT revalidate on failure', async () => {
    mockCreateTicket.mockResolvedValue({ success: false, error: 'Missing required field: subject' });
    await createTicketAction({ customer_id: 1 });
    expect(mockRevalidatePath).not.toHaveBeenCalled();
  });
});

describe('updateTicketAction', () => {
  it('delegates and revalidates on success', async () => {
    mockUpdateTicket.mockResolvedValue({ success: true });
    await updateTicketAction(2, { status: 'resolved' });
    expect(mockUpdateTicket).toHaveBeenCalledWith(FAKE_DB, 2, { status: 'resolved' });
    expect(mockRevalidatePath).toHaveBeenCalledWith('/crm-dashboard-advanced');
  });
});

describe('addTicketInteractionAction', () => {
  it('delegates and revalidates on success', async () => {
    mockAddTicketInteraction.mockResolvedValue({ success: true, interaction_id: 1 });
    const input = { ticket_id: 1, interaction_type: 'note', content: 'hi' };
    const result = await addTicketInteractionAction(input);
    expect(mockAddTicketInteraction).toHaveBeenCalledWith(FAKE_DB, input);
    expect(result).toEqual({ success: true, interaction_id: 1 });
    expect(mockRevalidatePath).toHaveBeenCalledWith('/crm-dashboard-advanced');
  });
});

describe('getCustomer360Action', () => {
  it('is read-only — delegates to getCustomer360 without ever revalidating', async () => {
    mockGetCustomer360.mockResolvedValue({ id: 1, display_name: 'A' });
    const result = await getCustomer360Action(1);
    expect(mockGetCustomer360).toHaveBeenCalledWith(FAKE_DB, 1);
    expect(result).toEqual({ id: 1, display_name: 'A' });
    expect(mockRevalidatePath).not.toHaveBeenCalled();
  });
});
