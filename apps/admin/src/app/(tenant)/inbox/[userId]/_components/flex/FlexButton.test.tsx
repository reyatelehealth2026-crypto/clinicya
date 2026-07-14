import { render, screen } from '@testing-library/react';
import { FlexButton } from './FlexButton';

describe('FlexButton', () => {
  it('renders a plain inert button for a message/postback action (no href, no onclick)', () => {
    render(<FlexButton button={{ type: 'button', action: { type: 'message', label: 'สั่งซื้อ' } }} />);
    const btn = screen.getByRole('button', { name: 'สั่งซื้อ' });
    expect(btn).toHaveAttribute('type', 'button');
  });

  it('renders an anchor for a uri action (structural stand-in for onclick=window.open)', () => {
    render(<FlexButton button={{ type: 'button', action: { type: 'uri', uri: 'https://line.me', label: 'เปิดลิงก์' } }} />);
    const link = screen.getByRole('link', { name: 'เปิดลิงก์' });
    expect(link).toHaveAttribute('href', 'https://line.me');
    expect(link).toHaveAttribute('target', '_blank');
  });

  it('defaults the label to "Button" when action.label is absent', () => {
    render(<FlexButton button={{ type: 'button' }} />);
    expect(screen.getByRole('button', { name: 'Button' })).toBeInTheDocument();
  });

  it('primary style uses color as background + white text; secondary uses transparent bg + colored text', () => {
    const { rerender } = render(<FlexButton button={{ type: 'button', style: 'primary', color: '#123456', action: { label: 'A' } }} />);
    expect(screen.getByRole('button')).toHaveStyle({ background: '#123456', color: '#FFFFFF' });

    rerender(<FlexButton button={{ type: 'button', style: 'secondary', color: '#123456', action: { label: 'A' } }} />);
    expect(screen.getByRole('button')).toHaveStyle({ background: 'transparent', color: '#123456' });
  });
});
