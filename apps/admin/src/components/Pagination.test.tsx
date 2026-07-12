import { render, screen } from '@testing-library/react';
import { Pagination } from './Pagination';

describe('Pagination', () => {
  it('renders only the info line (no nav) when there is a single page', () => {
    render(<Pagination currentPage={1} totalPages={1} perPage={20} basePath="/users" total={5} />);
    expect(screen.getByText(/รายการ/)).toBeInTheDocument();
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
  });

  it('renders prev/next and numbered links, preserving query params, when there are multiple pages', () => {
    render(
      <Pagination
        currentPage={2}
        totalPages={5}
        perPage={20}
        basePath="/users"
        queryParams={{ search: 'somsri' }}
        total={100}
      />
    );
    const prevHref = screen.getByRole('link', { name: 'Previous' }).getAttribute('href') ?? '';
    expect(prevHref).toContain('page=1');
    expect(prevHref).toContain('search=somsri');

    const nextHref = screen.getByRole('link', { name: 'Next' }).getAttribute('href') ?? '';
    expect(nextHref).toContain('page=3');

    expect(screen.getByText('3')).toBeInTheDocument();
  });

  it('disables Previous on page 1 and Next on the last page', () => {
    render(<Pagination currentPage={1} totalPages={3} perPage={20} basePath="/users" total={60} />);
    expect(screen.getByLabelText('Previous')).toHaveAttribute('aria-disabled', 'true');
    expect(screen.getByRole('link', { name: 'Next' })).toBeInTheDocument();
  });

  it('shows the current page as non-link, current text', () => {
    render(<Pagination currentPage={2} totalPages={3} perPage={20} basePath="/users" total={60} />);
    expect(screen.getByText('2')).toHaveAttribute('aria-current', 'page');
  });
});
