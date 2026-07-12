import { render, screen } from '@testing-library/react';
import { Toolbar } from './Toolbar';

describe('Toolbar', () => {
  it('renders a GET search form with the current search value pre-filled', () => {
    render(<Toolbar search={{ name: 'search', value: 'somsri', placeholder: 'ค้นหา…' }} />);
    const form = document.querySelector('form');
    expect(form).toHaveAttribute('method', 'GET');
    expect(screen.getByPlaceholderText('ค้นหา…')).toHaveValue('somsri');
  });

  it('renders hidden fields to preserve non-search params', () => {
    render(<Toolbar hiddenFields={{ tab: 'line' }} />);
    const hidden = document.querySelector('input[name="tab"]');
    expect(hidden).toHaveAttribute('value', 'line');
    expect(hidden).toHaveAttribute('type', 'hidden');
  });

  it('shows the active filter count badge on the advanced-filters toggle', () => {
    render(<Toolbar advanced={<div>filters</div>} activeFilterCount={3} />);
    expect(screen.getByText('3')).toBeInTheDocument();
  });

  it('renders a reset link when resetHref is given', () => {
    render(<Toolbar resetHref="/users" />);
    expect(screen.getByRole('link', { name: 'ล้างตัวกรอง' })).toHaveAttribute('href', '/users');
  });
});
