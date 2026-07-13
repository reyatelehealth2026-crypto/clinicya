import { fireEvent, render, screen } from '@testing-library/react';
import { DataTable, type DataTableColumn } from './DataTable';

interface Row {
  id: number;
  name: string;
}

const columns: DataTableColumn<Row>[] = [{ key: 'name', label: 'ชื่อ', render: (row) => row.name }];

describe('DataTable', () => {
  it('renders one row per item using the render callback', () => {
    render(<DataTable columns={columns} rows={[{ id: 1, name: 'Somsri' }, { id: 2, name: 'Anan' }]} />);
    expect(screen.getByText('Somsri')).toBeInTheDocument();
    expect(screen.getByText('Anan')).toBeInTheDocument();
  });

  it('renders emptyContent when rows is empty', () => {
    render(<DataTable columns={columns} rows={[]} emptyContent="ไม่พบผู้ใช้" />);
    expect(screen.getByText('ไม่พบผู้ใช้')).toBeInTheDocument();
  });

  it('does not render a checkbox column when selectable is false', () => {
    render(<DataTable columns={columns} rows={[{ id: 1, name: 'Somsri' }]} />);
    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
  });

  it('reports selected row ids via onSelectionChange when a row checkbox is toggled', () => {
    const onSelectionChange = jest.fn();
    render(
      <DataTable
        columns={columns}
        rows={[{ id: 1, name: 'Somsri' }, { id: 2, name: 'Anan' }]}
        selectable
        onSelectionChange={onSelectionChange}
      />
    );
    const rowCheckboxes = screen.getAllByRole('checkbox').filter((cb) => cb.getAttribute('aria-label') !== 'Select all rows');
    fireEvent.click(rowCheckboxes[0]!);
    expect(onSelectionChange).toHaveBeenLastCalledWith(['1']);
  });

  it('selects every row when the header checkbox is toggled on', () => {
    const onSelectionChange = jest.fn();
    render(
      <DataTable
        columns={columns}
        rows={[{ id: 1, name: 'Somsri' }, { id: 2, name: 'Anan' }]}
        selectable
        onSelectionChange={onSelectionChange}
      />
    );
    fireEvent.click(screen.getByLabelText('Select all rows'));
    expect(onSelectionChange).toHaveBeenLastCalledWith(['1', '2']);
  });
});
