import { fireEvent, render, screen } from '@testing-library/react';
import { FilterBar, type FilterBarAdmin, type FilterBarTag } from './FilterBar';

interface TestItem {
  id: number;
  chatStatus?: string;
  tags?: string;
  assigned?: '0' | '1';
  assignees?: string;
  hasUnread?: boolean;
}

function TestHarness({ items, currentAdminId = 1 }: { items: TestItem[]; currentAdminId?: number }) {
  const tags: FilterBarTag[] = [
    { id: 1, name: 'VIP' },
    { id: 2, name: 'New' },
  ];
  const admins: FilterBarAdmin[] = [{ id: 9, username: 'admin9', display_name: 'Admin Nine' }];

  return (
    <div>
      <FilterBar tags={tags} admins={admins} currentAdminId={currentAdminId} />
      <div id="userList">
        {items.map((item) => (
          <a
            key={item.id}
            className="user-item"
            data-testid={`item-${item.id}`}
            data-user-id={item.id}
            data-chat-status={item.chatStatus ?? ''}
            data-tags={item.tags ?? ''}
            data-assigned={item.assigned ?? '0'}
            data-assignees={item.assignees ?? ''}
          >
            {item.hasUnread ? <div className="unread-badge">1</div> : null}
          </a>
        ))}
      </div>
    </div>
  );
}

function isHidden(id: number): boolean {
  const el = screen.getByTestId(`item-${id}`);
  return el.classList.contains('filter-hidden') && el.style.display === 'none';
}
function isShown(id: number): boolean {
  const el = screen.getByTestId(`item-${id}`);
  return !el.classList.contains('filter-hidden') && el.style.display !== 'none';
}

describe('FilterBar', () => {
  let fetchMock: jest.Mock;
  const originalFetch = global.fetch;

  beforeEach(() => {
    fetchMock = jest.fn();
    global.fetch = fetchMock as unknown as typeof fetch;
  });

  afterEach(() => {
    global.fetch = originalFetch;
  });

  it('status=unread shows only items with a .unread-badge child', () => {
    render(<TestHarness items={[{ id: 1, hasUnread: true }, { id: 2, hasUnread: false }]} />);
    fireEvent.change(document.getElementById('filterStatus')!, { target: { value: 'unread' } });
    expect(isShown(1)).toBe(true);
    expect(isHidden(2)).toBe(true);
  });

  it('status=assigned shows only items with data-assigned="1"', () => {
    render(<TestHarness items={[{ id: 1, assigned: '1' }, { id: 2, assigned: '0' }]} />);
    fireEvent.change(document.getElementById('filterStatus')!, { target: { value: 'assigned' } });
    expect(isShown(1)).toBe(true);
    expect(isHidden(2)).toBe(true);
  });

  it('tag filter shows only items whose data-tags CSV includes the selected tag id', () => {
    render(<TestHarness items={[{ id: 1, tags: '1,2' }, { id: 2, tags: '3' }, { id: 3, tags: '' }]} />);
    fireEvent.change(document.getElementById('filterTag')!, { target: { value: '1' } });
    expect(isShown(1)).toBe(true);
    expect(isHidden(2)).toBe(true);
    expect(isHidden(3)).toBe(true);
  });

  it('chatStatus filter is an exact match against data-chat-status', () => {
    render(<TestHarness items={[{ id: 1, chatStatus: 'pending' }, { id: 2, chatStatus: 'completed' }]} />);
    fireEvent.change(document.getElementById('filterChatStatus')!, { target: { value: 'pending' } });
    expect(isShown(1)).toBe(true);
    expect(isHidden(2)).toBe(true);
  });

  it('assignee="me" shows only items whose data-assignees includes the current admin id', () => {
    render(<TestHarness items={[{ id: 1, assigned: '1', assignees: '1,2' }, { id: 2, assigned: '1', assignees: '5' }]} currentAdminId={1} />);
    fireEvent.change(document.getElementById('filterAssignee')!, { target: { value: 'me' } });
    expect(isShown(1)).toBe(true);
    expect(isHidden(2)).toBe(true);
  });

  it('assignee="unassigned" shows only items with data-assigned="0"', () => {
    render(<TestHarness items={[{ id: 1, assigned: '0' }, { id: 2, assigned: '1', assignees: '9' }]} />);
    fireEvent.change(document.getElementById('filterAssignee')!, { target: { value: 'unassigned' } });
    expect(isShown(1)).toBe(true);
    expect(isHidden(2)).toBe(true);
  });

  it('assignee=<adminId> shows only items whose data-assignees includes that id', () => {
    render(<TestHarness items={[{ id: 1, assigned: '1', assignees: '9' }, { id: 2, assigned: '1', assignees: '10' }]} />);
    fireEvent.change(document.getElementById('filterAssignee')!, { target: { value: '9' } });
    expect(isShown(1)).toBe(true);
    expect(isHidden(2)).toBe(true);
  });

  it('combines multiple active filters with AND', () => {
    render(
      <TestHarness
        items={[
          { id: 1, chatStatus: 'pending', tags: '1', assigned: '1', assignees: '9' },
          { id: 2, chatStatus: 'pending', tags: '2', assigned: '1', assignees: '9' },
        ]}
      />
    );
    fireEvent.change(document.getElementById('filterChatStatus')!, { target: { value: 'pending' } });
    fireEvent.change(document.getElementById('filterTag')!, { target: { value: '1' } });
    expect(isShown(1)).toBe(true);
    expect(isHidden(2)).toBe(true);
  });

  it('clearing every filter back to "" re-shows every item', () => {
    render(<TestHarness items={[{ id: 1, chatStatus: 'pending' }, { id: 2, chatStatus: 'completed' }]} />);
    const select = document.getElementById('filterChatStatus')! as HTMLSelectElement;
    fireEvent.change(select, { target: { value: 'pending' } });
    expect(isHidden(2)).toBe(true);
    fireEvent.change(select, { target: { value: '' } });
    expect(isShown(1)).toBe(true);
    expect(isShown(2)).toBe(true);
  });

  it('fires zero network calls for any filter interaction', () => {
    render(<TestHarness items={[{ id: 1 }, { id: 2 }]} />);
    fireEvent.change(document.getElementById('filterStatus')!, { target: { value: 'unread' } });
    fireEvent.change(document.getElementById('filterTag')!, { target: { value: '1' } });
    fireEvent.change(document.getElementById('filterChatStatus')!, { target: { value: 'pending' } });
    fireEvent.change(document.getElementById('filterAssignee')!, { target: { value: 'me' } });
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('renders tag and admin options from props', () => {
    render(<TestHarness items={[]} />);
    expect(screen.getByRole('option', { name: 'VIP' })).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'Admin Nine' })).toBeInTheDocument();
  });
});
