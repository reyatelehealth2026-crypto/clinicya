import { render, screen } from '@testing-library/react';
import type { ConversationRow } from '@/app/api/inbox/conversations/_lib/query';
import { ConversationListItem } from './ConversationListItem';

const NOW = new Date(Date.UTC(2026, 6, 14, 8, 0, 0)); // Bangkok 2026-07-14 15:00:00

function baseRow(overrides: Partial<ConversationRow> = {}): ConversationRow {
  return {
    id: 42,
    display_name: 'Somsri',
    picture_url: null,
    chat_status: null,
    platform: 'line',
    platform_user_id: null,
    last_message_at: '2026-07-14 09:30:00',
    assigned_to: null,
    assignment_status: null,
    unread_count: 0,
    last_message_preview: 'สวัสดีครับ',
    last_message_type: 'text',
    tags: [],
    assignees: [],
    ...overrides,
  };
}

describe('ConversationListItem', () => {
  it('renders display name, formatted time, and message preview', () => {
    render(<ConversationListItem conversation={baseRow()} now={NOW} />);
    expect(screen.getByText('Somsri')).toBeInTheDocument();
    expect(screen.getByText('09:30 น.')).toBeInTheDocument();
    expect(screen.getByText('สวัสดีครับ')).toBeInTheDocument();
  });

  it('links to /inbox/{id}', () => {
    render(<ConversationListItem conversation={baseRow({ id: 7 })} now={NOW} />);
    expect(screen.getByRole('link')).toHaveAttribute('href', '/inbox/7');
  });

  it('sets every data-* attribute FilterBar depends on', () => {
    render(
      <ConversationListItem
        conversation={baseRow({
          id: 7,
          display_name: 'Somsri',
          chat_status: 'pending',
          tags: [
            { id: 1, name: 'VIP', color: null },
            { id: 2, name: 'New', color: null },
          ],
          assignees: [9, 10],
        })}
        now={NOW}
      />
    );
    const link = screen.getByRole('link');
    expect(link).toHaveAttribute('data-user-id', '7');
    expect(link).toHaveAttribute('data-name', 'somsri');
    expect(link).toHaveAttribute('data-chat-status', 'pending');
    expect(link).toHaveAttribute('data-tags', '1,2');
    expect(link).toHaveAttribute('data-assigned', '1');
    expect(link).toHaveAttribute('data-assignees', '9,10');
    expect(link).toHaveClass('user-item');
  });

  it('data-assigned=0 and empty data-assignees when unassigned', () => {
    render(<ConversationListItem conversation={baseRow({ assignees: [] })} now={NOW} />);
    const link = screen.getByRole('link');
    expect(link).toHaveAttribute('data-assigned', '0');
    expect(link).toHaveAttribute('data-assignees', '');
  });

  it('shows the unread badge with the exact count when 1-9', () => {
    render(<ConversationListItem conversation={baseRow({ unread_count: 3 })} now={NOW} />);
    expect(screen.getByText('3')).toBeInTheDocument();
  });

  it('caps the unread badge display at "9+"', () => {
    render(<ConversationListItem conversation={baseRow({ unread_count: 42 })} now={NOW} />);
    expect(screen.getByText('9+')).toBeInTheDocument();
  });

  it('renders no .unread-badge element when unread_count is 0 (FilterBar\'s status=unread predicate)', () => {
    const { container } = render(<ConversationListItem conversation={baseRow({ unread_count: 0 })} now={NOW} />);
    expect(container.querySelector('.unread-badge')).toBeNull();
  });

  it('falls back to the placeholder avatar when picture_url is null', () => {
    render(<ConversationListItem conversation={baseRow({ picture_url: null })} now={NOW} />);
    expect(screen.getByRole('img').getAttribute('src')).toContain('data:image/svg+xml');
  });

  it('uses the real picture_url when present', () => {
    render(<ConversationListItem conversation={baseRow({ picture_url: 'https://example.com/a.png' })} now={NOW} />);
    expect(screen.getByRole('img')).toHaveAttribute('src', 'https://example.com/a.png');
  });

  it('renders a chat-status badge icon matching the PHP badge config', () => {
    render(<ConversationListItem conversation={baseRow({ chat_status: 'shipping' })} now={NOW} />);
    expect(screen.getByText('📦')).toBeInTheDocument();
  });

  it('renders no badge for an unrecognized/empty chat_status', () => {
    const { container } = render(<ConversationListItem conversation={baseRow({ chat_status: null })} now={NOW} />);
    expect(container.querySelector('.chat-status-badge')).toBeNull();
  });

  it('single assignee -> generic "มอบหมายแล้ว" text (no name on the wire, see module doc)', () => {
    render(<ConversationListItem conversation={baseRow({ assignees: [5] })} now={NOW} />);
    expect(screen.getByText('มอบหมายแล้ว')).toBeInTheDocument();
  });

  it('multiple assignees -> "{count} คน"', () => {
    render(<ConversationListItem conversation={baseRow({ assignees: [5, 6, 7] })} now={NOW} />);
    expect(screen.getByText('3 คน')).toBeInTheDocument();
  });

  it('appends the "active" class when isActive', () => {
    render(<ConversationListItem conversation={baseRow()} isActive now={NOW} />);
    expect(screen.getByRole('link')).toHaveClass('active');
  });

  it('does not have the "active" class by default', () => {
    render(<ConversationListItem conversation={baseRow()} now={NOW} />);
    expect(screen.getByRole('link')).not.toHaveClass('active');
  });
});
