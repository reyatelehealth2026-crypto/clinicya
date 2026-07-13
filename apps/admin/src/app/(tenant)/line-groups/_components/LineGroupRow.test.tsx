import { render, screen, fireEvent } from '@testing-library/react';

jest.mock('../../users/_lib/session', () => ({
  requireTenantPageContext: () => Promise.resolve({ db: {}, session: { currentBotId: 1 } }),
}));
jest.mock('next/navigation', () => ({
  redirect: jest.fn(),
}));

import { LineGroupRow } from './LineGroupRow';
import type { LineGroupRow as LineGroupRowData } from '../queries';

function makeGroup(overrides: Partial<LineGroupRowData> = {}): LineGroupRowData {
  return {
    id: 1,
    groupId: 'C1',
    groupType: 'group',
    groupName: 'หมอดี คลินิก',
    pictureUrl: null,
    memberCount: 5,
    isActive: 1,
    joinedAt: new Date('2026-01-01T10:00:00+07:00'),
    totalMessages: 20,
    lineAccountId: 1,
    botName: 'Bot A',
    ...overrides,
  };
}

function renderRow(group: LineGroupRowData) {
  return render(
    <table>
      <tbody>
        <LineGroupRow group={group} />
      </tbody>
    </table>
  );
}

describe('LineGroupRow', () => {
  it('links "ดูรายละเอียด" to /line-group-detail?id=<id>', () => {
    renderRow(makeGroup({ id: 42 }));
    expect(screen.getByTitle('ดูรายละเอียด')).toHaveAttribute('href', '/line-group-detail?id=42');
  });

  it('renders the send-message button disabled, pointing at the still-live PHP page in its title', () => {
    renderRow(makeGroup());
    const sendBtn = screen.getByTitle(/ส่งข้อความ/);
    expect(sendBtn).toBeDisabled();
    expect(sendBtn.getAttribute('title')).toContain('line-groups.php');
  });

  it('asks for confirmation with the exact PHP-mirrored message before leaving', () => {
    const confirmSpy = jest.spyOn(window, 'confirm').mockReturnValue(false);
    renderRow(makeGroup({ groupName: 'X Room' }));
    fireEvent.click(screen.getByTitle('ออกจากกลุ่ม'));
    expect(confirmSpy).toHaveBeenCalledWith(
      'ต้องการให้บอทออกจากกลุ่ม "X Room" หรือไม่?\n\nหมายเหตุ: บอทจะไม่สามารถกลับเข้ากลุ่มได้เอง ต้องให้สมาชิกเชิญใหม่'
    );
    confirmSpy.mockRestore();
  });

  it('renders no action icons at all for an inactive group (isActive falsy)', () => {
    renderRow(makeGroup({ isActive: 0 }));
    expect(screen.queryByTitle('ออกจากกลุ่ม')).not.toBeInTheDocument();
    expect(screen.queryByTitle(/ส่งข้อความ/)).not.toBeInTheDocument();
    // The view-detail link still renders regardless of active state.
    expect(screen.getByTitle('ดูรายละเอียด')).toBeInTheDocument();
  });

  it('falls back to "Unknown" for a null group name and "Room"/"Group" label from group_type', () => {
    renderRow(makeGroup({ groupName: null, groupType: 'room' }));
    expect(screen.getByText('Unknown')).toBeInTheDocument();
    expect(screen.getByText('Room')).toBeInTheDocument();
  });
});
