import { render, screen } from '@testing-library/react';
import { PageHeader } from './PageHeader';

describe('PageHeader', () => {
  it('renders title and subtitle', () => {
    render(<PageHeader title="Customers" subtitle="ทั้งหมด 10 คน" />);
    expect(screen.getByRole('heading', { name: 'Customers' })).toBeInTheDocument();
    expect(screen.getByText('ทั้งหมด 10 คน')).toBeInTheDocument();
  });

  it('renders a link-style primary action with the given href', () => {
    render(<PageHeader title="Customers" primaryAction={{ label: 'Odoo Dashboard', href: '/odoo-dashboard' }} />);
    const link = screen.getByRole('link', { name: /Odoo Dashboard/ });
    expect(link).toHaveAttribute('href', '/odoo-dashboard');
  });

  it('renders breadcrumb items, linking every item except the last', () => {
    render(
      <PageHeader
        title="รายละเอียด"
        breadcrumb={[
          { label: 'Customers', href: '/users' },
          { label: 'รายละเอียด', href: null },
        ]}
      />
    );
    const customersLink = screen.getByRole('link', { name: 'Customers' });
    expect(customersLink).toHaveAttribute('href', '/users');
    // Last crumb renders as plain text, not a link.
    expect(screen.queryByRole('link', { name: 'รายละเอียด' })).not.toBeInTheDocument();
  });

  it('omits the primary action entirely when none is given', () => {
    render(<PageHeader title="Customers" />);
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
  });
});
