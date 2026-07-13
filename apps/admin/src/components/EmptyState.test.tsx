import { render, screen } from '@testing-library/react';
import { EmptyState } from './EmptyState';

describe('EmptyState', () => {
  it('renders heading and optional sub text', () => {
    render(<EmptyState heading="ไม่พบผู้ใช้" sub="ลองปรับตัวกรอง" />);
    expect(screen.getByText('ไม่พบผู้ใช้')).toBeInTheDocument();
    expect(screen.getByText('ลองปรับตัวกรอง')).toBeInTheDocument();
  });

  it('omits the CTA link when none is given', () => {
    render(<EmptyState heading="ไม่พบผู้ใช้" />);
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
  });

  it('renders a CTA link when given', () => {
    render(<EmptyState heading="ไม่พบผู้ใช้" cta={{ label: 'ล้างตัวกรอง', href: '/users' }} />);
    expect(screen.getByRole('link', { name: 'ล้างตัวกรอง' })).toHaveAttribute('href', '/users');
  });
});
