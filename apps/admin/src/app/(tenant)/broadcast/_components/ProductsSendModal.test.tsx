import { fireEvent, render, screen, waitFor } from '@testing-library/react';

const mockSendProductBroadcastAction = jest.fn();
jest.mock('../_lib/products-actions', () => ({
  sendProductBroadcastAction: (fd: FormData) => mockSendProductBroadcastAction(fd),
}));

import { ProductsSendModal } from './ProductsSendModal';

const TAGS = [
  { id: 1, name: 'สนใจโปร', color: '#ff0000' },
  { id: 2, name: 'ลูกค้าเก่า', color: '#00ff00' },
];

beforeEach(() => {
  jest.clearAllMocks();
});

describe('ProductsSendModal — products.php lines 418-473', () => {
  it('shows the "ส่ง" trigger button; the modal is closed until clicked', () => {
    render(<ProductsSendModal campaignId={5} campaignName="แคมเปญ A" tags={TAGS} />);
    expect(screen.queryByText('📤 ส่ง Broadcast')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: /ส่ง/ }));
    expect(screen.getByText('📤 ส่ง Broadcast')).toBeInTheDocument();
    expect(screen.getByText('แคมเปญ A')).toBeInTheDocument();
  });

  it('defaults to target_type=all; the tag checklist is hidden until "เฉพาะ Tag ที่เลือก" is chosen', () => {
    render(<ProductsSendModal campaignId={5} campaignName="แคมเปญ A" tags={TAGS} />);
    fireEvent.click(screen.getByRole('button', { name: /ส่ง/ }));
    expect(screen.queryByText('สนใจโปร')).not.toBeInTheDocument();
    fireEvent.click(screen.getByRole('radio', { name: /เฉพาะ Tag ที่เลือก/ }));
    expect(screen.getByText('สนใจโปร')).toBeInTheDocument();
    expect(screen.getByText('ลูกค้าเก่า')).toBeInTheDocument();
  });

  it('submits with action=send_broadcast, campaign_id, and target_type=all by default', async () => {
    mockSendProductBroadcastAction.mockResolvedValue({ error: null });
    render(<ProductsSendModal campaignId={7} campaignName="แคมเปญ A" tags={TAGS} />);
    fireEvent.click(screen.getByRole('button', { name: /ส่ง/ }));
    fireEvent.click(screen.getByRole('button', { name: 'ส่ง Broadcast' }));

    await waitFor(() => expect(mockSendProductBroadcastAction).toHaveBeenCalledTimes(1));
    const fd = mockSendProductBroadcastAction.mock.calls[0]![0] as FormData;
    expect(fd.get('action')).toBe('send_broadcast');
    expect(fd.get('campaign_id')).toBe('7');
    expect(fd.get('target_type')).toBe('all');
    expect(fd.getAll('target_tags[]')).toEqual([]);
  });

  it('submits selected tag ids as repeated target_tags[] entries when target_type=tags', async () => {
    mockSendProductBroadcastAction.mockResolvedValue({ error: null });
    render(<ProductsSendModal campaignId={7} campaignName="แคมเปญ A" tags={TAGS} />);
    fireEvent.click(screen.getByRole('button', { name: /ส่ง/ }));
    fireEvent.click(screen.getByRole('radio', { name: /เฉพาะ Tag ที่เลือก/ }));
    fireEvent.click(screen.getByRole('checkbox', { name: /สนใจโปร/ }));
    fireEvent.click(screen.getByRole('checkbox', { name: /ลูกค้าเก่า/ }));
    fireEvent.click(screen.getByRole('button', { name: 'ส่ง Broadcast' }));

    await waitFor(() => expect(mockSendProductBroadcastAction).toHaveBeenCalledTimes(1));
    const fd = mockSendProductBroadcastAction.mock.calls[0]![0] as FormData;
    expect(fd.get('target_type')).toBe('tags');
    expect(fd.getAll('target_tags[]').sort()).toEqual(['1', '2']);
  });

  it('shows the returned Thai error inline and keeps the modal open when the action returns {error}', async () => {
    mockSendProductBroadcastAction.mockResolvedValue({ error: 'ไม่พบ Campaign' });
    render(<ProductsSendModal campaignId={999} campaignName="แคมเปญ A" tags={TAGS} />);
    fireEvent.click(screen.getByRole('button', { name: /ส่ง/ }));
    fireEvent.click(screen.getByRole('button', { name: 'ส่ง Broadcast' }));

    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('ไม่พบ Campaign'));
    expect(screen.getByText('📤 ส่ง Broadcast')).toBeInTheDocument(); // modal still open
  });

  it('"ยกเลิก" closes the modal without calling the action', () => {
    render(<ProductsSendModal campaignId={5} campaignName="แคมเปญ A" tags={TAGS} />);
    fireEvent.click(screen.getByRole('button', { name: /ส่ง/ }));
    fireEvent.click(screen.getByRole('button', { name: 'ยกเลิก' }));
    expect(screen.queryByText('📤 ส่ง Broadcast')).not.toBeInTheDocument();
    expect(mockSendProductBroadcastAction).not.toHaveBeenCalled();
  });
});
