import { render, screen, fireEvent } from '@testing-library/react';

// GroupsPanel imports ../actions, which calls requireTenantPageContext() and
// next/navigation's redirect() at call time (not module-eval time) — mocked
// here the same way page.test.tsx mocks them, so the real actions.ts module
// loads safely under jsdom without hitting real Next server internals.
jest.mock('../../users/_lib/session', () => ({
  requireTenantPageContext: () => Promise.resolve({ db: {}, session: { currentBotId: 1 } }),
}));
jest.mock('next/navigation', () => ({
  redirect: jest.fn(),
}));

import { GroupsPanel } from './GroupsPanel';
import type { GroupRow, GroupDetailRow, GroupMemberRow } from '../queries';

const groups: GroupRow[] = [
  { id: 1, name: 'VIP', description: 'desc', color: '#3B82F6', createdAt: new Date(), lineAccountId: null, memberCount: 2 },
];

describe('GroupsPanel', () => {
  it('renders the "select a group" placeholder when no group is being viewed', () => {
    render(<GroupsPanel groups={groups} allUsers={[]} viewGroupId={null} viewGroup={null} members={[]} />);
    expect(screen.getByText('เลือกกลุ่มเพื่อดูรายละเอียด')).toBeInTheDocument();
  });

  it('links each group row to ?view=<id>', () => {
    render(<GroupsPanel groups={groups} allUsers={[]} viewGroupId={null} viewGroup={null} members={[]} />);
    expect(screen.getByText('VIP').closest('a')).toHaveAttribute('href', '/groups?view=1');
  });

  it('highlights the currently-viewed group row', () => {
    const viewGroup: GroupDetailRow = { id: 1, name: 'VIP', description: 'desc', color: '#3B82F6', createdAt: new Date(), lineAccountId: null };
    render(<GroupsPanel groups={groups} allUsers={[]} viewGroupId={1} viewGroup={viewGroup} members={[]} />);
    // Both the list row and the detail-panel header render "VIP" — the row link is the first occurrence.
    expect(screen.getAllByText('VIP')[0]?.closest('a')).toHaveClass('bg-green-50');
  });

  it('opens the create modal with empty fields when the "+" button is clicked', () => {
    render(<GroupsPanel groups={[]} allUsers={[]} viewGroupId={null} viewGroup={null} members={[]} />);
    fireEvent.click(screen.getByRole('button', { name: 'สร้างกลุ่มใหม่' }));
    expect(screen.getByRole('heading', { name: 'สร้างกลุ่มใหม่' })).toBeInTheDocument();
    expect(screen.getByLabelText('ชื่อกลุ่ม')).toHaveValue('');
  });

  it('opens the edit modal pre-filled with the viewed group\'s data', () => {
    const viewGroup: GroupDetailRow = { id: 1, name: 'VIP', description: 'desc', color: '#3B82F6', createdAt: new Date(), lineAccountId: null };
    render(<GroupsPanel groups={groups} allUsers={[]} viewGroupId={1} viewGroup={viewGroup} members={[]} />);
    fireEvent.click(screen.getByRole('button', { name: 'แก้ไขกลุ่ม' }));
    expect(screen.getByLabelText('ชื่อกลุ่ม')).toHaveValue('VIP');
    expect(screen.getByLabelText('คำอธิบาย')).toHaveValue('desc');
  });

  it('shows the member list and "add member" dropdown for a viewed group', () => {
    const viewGroup: GroupDetailRow = { id: 1, name: 'VIP', description: 'desc', color: '#3B82F6', createdAt: new Date(), lineAccountId: null };
    const members: GroupMemberRow[] = [{ id: 9, displayName: 'สมศรี', pictureUrl: null }];
    render(
      <GroupsPanel
        groups={groups}
        allUsers={[{ id: 9, displayName: 'สมศรี', pictureUrl: null }]}
        viewGroupId={1}
        viewGroup={viewGroup}
        members={members}
      />
    );
    expect(screen.getByLabelText('นำ สมศรี ออกจากกลุ่ม')).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'สมศรี' })).toBeInTheDocument();
  });

  it('shows the empty-members message when a viewed group has no members', () => {
    const viewGroup: GroupDetailRow = { id: 1, name: 'VIP', description: 'desc', color: '#3B82F6', createdAt: new Date(), lineAccountId: null };
    render(<GroupsPanel groups={groups} allUsers={[]} viewGroupId={1} viewGroup={viewGroup} members={[]} />);
    expect(screen.getByText('ยังไม่มีสมาชิกในกลุ่ม')).toBeInTheDocument();
  });

  it('asks for confirmation before letting the delete form submit, matching includes/footer.php\'s confirmDelete() default message', () => {
    const confirmSpy = jest.spyOn(window, 'confirm').mockReturnValue(false);
    const viewGroup: GroupDetailRow = { id: 1, name: 'VIP', description: 'desc', color: '#3B82F6', createdAt: new Date(), lineAccountId: null };
    render(<GroupsPanel groups={groups} allUsers={[]} viewGroupId={1} viewGroup={viewGroup} members={[]} />);

    const deleteButton = screen.getByRole('button', { name: 'ลบ' });
    fireEvent.click(deleteButton);

    expect(confirmSpy).toHaveBeenCalledWith('คุณแน่ใจหรือไม่ที่จะลบ?');
    confirmSpy.mockRestore();
  });
});
