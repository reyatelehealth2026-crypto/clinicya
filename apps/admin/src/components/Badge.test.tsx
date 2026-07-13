import { fireEvent, render, screen } from '@testing-library/react';
import { Badge, TagChip, UserStatusBadge } from './Badge';

describe('Badge', () => {
  it('renders children with the tone class', () => {
    render(<Badge tone="danger">Blocked</Badge>);
    expect(screen.getByText('Blocked')).toHaveClass('badge-danger');
  });
});

describe('UserStatusBadge', () => {
  it('shows Blocked for a blocked user', () => {
    render(<UserStatusBadge isBlocked />);
    expect(screen.getByText('Blocked')).toBeInTheDocument();
  });

  it('shows Active for a non-blocked user', () => {
    render(<UserStatusBadge isBlocked={false} />);
    expect(screen.getByText('Active')).toBeInTheDocument();
  });
});

describe('TagChip', () => {
  it('renders the tag name and a remove button when onRemove is given', () => {
    const onRemove = jest.fn();
    render(<TagChip name="VIP" color="#ff0000" onRemove={onRemove} />);
    expect(screen.getByText('VIP')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Remove tag VIP' }));
    expect(onRemove).toHaveBeenCalledTimes(1);
  });

  it('omits the remove button when onRemove is not given', () => {
    render(<TagChip name="VIP" color="#ff0000" />);
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
  });
});
