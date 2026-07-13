import { render, screen } from '@testing-library/react';
import { Tabs } from './Tabs';

describe('Tabs', () => {
  const tabs = [
    { key: 'line', label: 'LINE Users' },
    { key: 'odoo', label: 'Odoo Customers' },
  ];

  it('marks the active tab with aria-current', () => {
    render(<Tabs tabs={tabs} activeTab="line" basePath="/users" />);
    expect(screen.getByRole('link', { name: 'LINE Users' })).toHaveAttribute('aria-current', 'page');
    expect(screen.getByRole('link', { name: 'Odoo Customers' })).not.toHaveAttribute('aria-current');
  });

  it('builds each tab href with tab= plus preserved params', () => {
    render(
      <Tabs
        tabs={tabs}
        activeTab="line"
        basePath="/users"
        preserveParams={{ search: 'somsri', tier: '' }}
      />
    );
    const href = screen.getByRole('link', { name: 'Odoo Customers' }).getAttribute('href');
    expect(href).toContain('/users?');
    expect(href).toContain('search=somsri');
    expect(href).toContain('tab=odoo');
    // Empty-string params are not preserved.
    expect(href).not.toContain('tier=');
  });

  it('disabled tabs link to # and are not clickable navigations', () => {
    render(<Tabs tabs={[{ key: 'odoo', label: 'Odoo Customers', disabled: true }]} activeTab="line" basePath="/users" />);
    expect(screen.getByRole('link', { name: 'Odoo Customers' })).toHaveAttribute('href', '#');
  });
});
