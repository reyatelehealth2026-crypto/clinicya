'use client';

import { useState, type ReactNode } from 'react';

/**
 * DataTable — React port of includes/components/data-table.php's
 * renderDataTable($columns, $rows, $options). Client Component (checkbox
 * selection needs onChange handlers); when `selectable` is omitted this
 * renders as a plain table with no client-only behavior beyond the
 * (harmless) hydration boundary.
 *
 * Mirrors the PHP contract's shape closely on purpose (columns:
 * {key,label,align,render}, rows, options: {emptyContent,rowKey,selectable,
 * onSelectionChange}) rather than inventing a new one, so a page ported from
 * a `renderDataTable(...)` call site translates almost mechanically.
 */
export interface DataTableColumn<Row> {
  key: string;
  label: ReactNode;
  align?: 'left' | 'center' | 'right';
  width?: string;
  render: (row: Row) => ReactNode;
}

export interface DataTableProps<Row> {
  columns: DataTableColumn<Row>[];
  rows: Row[];
  /** Row field used both as the React key and the checkbox value — default 'id'. */
  rowKey?: keyof Row;
  emptyContent?: ReactNode;
  /** Enables the leading checkbox column; selection state is owned internally, read out via onSelectionChange. */
  selectable?: boolean;
  onSelectionChange?: (selectedIds: string[]) => void;
}

export function DataTable<Row>({
  columns,
  rows,
  rowKey = 'id' as keyof Row,
  emptyContent,
  selectable = false,
  onSelectionChange,
}: DataTableProps<Row>) {
  const [selected, setSelected] = useState<Set<string>>(new Set());

  function idOf(row: Row): string {
    return String(row[rowKey]);
  }

  function emit(next: Set<string>) {
    setSelected(next);
    onSelectionChange?.(Array.from(next));
  }

  function toggleRow(id: string, checked: boolean) {
    const next = new Set(selected);
    if (checked) {
      next.add(id);
    } else {
      next.delete(id);
    }
    emit(next);
  }

  function toggleAll(checked: boolean) {
    emit(checked ? new Set(rows.map(idOf)) : new Set());
  }

  const allSelected = rows.length > 0 && rows.every((row) => selected.has(idOf(row)));

  return (
    <div className="data-table-card">
      <div className="data-table-scroll">
        <table className="data-table">
          <thead>
            <tr>
              {selectable ? (
                <th className="data-table-th data-table-th-checkbox" style={{ width: 40 }}>
                  <input
                    type="checkbox"
                    className="data-table-checkbox"
                    checked={allSelected}
                    onChange={(e) => toggleAll(e.target.checked)}
                    aria-label="Select all rows"
                  />
                </th>
              ) : null}
              {columns.map((col) => (
                <th key={col.key} className={`data-table-th data-table-th-${col.align ?? 'left'}`} style={col.width ? { width: col.width } : undefined}>
                  {col.label}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 ? (
              <tr>
                <td colSpan={columns.length + (selectable ? 1 : 0)} className="data-table-empty-cell">
                  {emptyContent ?? 'ไม่พบข้อมูล'}
                </td>
              </tr>
            ) : (
              rows.map((row) => {
                const id = idOf(row);
                return (
                  <tr key={id} className="data-table-row" data-id={id}>
                    {selectable ? (
                      <td className="data-table-td data-table-td-checkbox" onClick={(e) => e.stopPropagation()}>
                        <input
                          type="checkbox"
                          className="data-table-checkbox user-checkbox"
                          checked={selected.has(id)}
                          onChange={(e) => toggleRow(id, e.target.checked)}
                          aria-label={`Select row ${id}`}
                        />
                      </td>
                    ) : null}
                    {columns.map((col) => (
                      <td key={col.key} className={`data-table-td data-table-td-${col.align ?? 'left'}`}>
                        {col.render(row)}
                      </td>
                    ))}
                  </tr>
                );
              })
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}
