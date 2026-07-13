import { render, screen, fireEvent, waitFor } from '@testing-library/react';

const mockRefresh = jest.fn();
jest.mock('next/navigation', () => ({
  useRouter: () => ({ refresh: mockRefresh }),
}));

const mockCreate = jest.fn();
const mockUpdate = jest.fn();
const mockDelete = jest.fn();
jest.mock('../actions', () => ({
  createTemplateAction: (...args: unknown[]) => mockCreate(...args),
  updateTemplateAction: (...args: unknown[]) => mockUpdate(...args),
  deleteTemplateAction: (...args: unknown[]) => mockDelete(...args),
}));

import { TemplatesClient } from './TemplatesClient';
import type { TemplateRow } from '../queries';

const templates: TemplateRow[] = [
  { id: 1, name: 'ทักทาย', category: 'ทั่วไป', messageType: 'text', content: 'สวัสดีครับ', createdAt: new Date() },
  { id: 2, name: 'โปร', category: 'โปรโมชั่น', messageType: 'flex', content: '{}', createdAt: new Date() },
];

beforeEach(() => {
  jest.clearAllMocks();
  mockCreate.mockResolvedValue({ success: true });
  mockUpdate.mockResolvedValue({ success: true });
  mockDelete.mockResolvedValue({ success: true });
});

describe('TemplatesClient', () => {
  it('shows every template by default (activeCategory = "")', () => {
    render(<TemplatesClient templates={templates} categories={['ทั่วไป', 'โปรโมชั่น']} />);
    expect(screen.getByText('ทักทาย')).toBeInTheDocument();
    expect(screen.getByText('โปร')).toBeInTheDocument();
  });

  it('filters cards to the clicked category, client-side, without a new query', () => {
    render(<TemplatesClient templates={templates} categories={['ทั่วไป', 'โปรโมชั่น']} />);
    fireEvent.click(screen.getByRole('button', { name: 'ทั่วไป' }));
    expect(screen.getByText('ทักทาย')).toBeInTheDocument();
    expect(screen.queryByText('โปร')).not.toBeInTheDocument();
  });

  it('shows all templates again after clicking "ทั้งหมด"', () => {
    render(<TemplatesClient templates={templates} categories={['ทั่วไป', 'โปรโมชั่น']} />);
    fireEvent.click(screen.getByRole('button', { name: 'ทั่วไป' }));
    fireEvent.click(screen.getByRole('button', { name: 'ทั้งหมด' }));
    expect(screen.getByText('ทักทาย')).toBeInTheDocument();
    expect(screen.getByText('โปร')).toBeInTheDocument();
  });

  it('opens the create modal, submits, and refreshes on save', async () => {
    render(<TemplatesClient templates={[]} categories={[]} />);
    fireEvent.click(screen.getByRole('button', { name: /เพิ่มเทมเพลต/ }));

    fireEvent.change(screen.getByLabelText('ชื่อเทมเพลต'), { target: { value: 'ใหม่' } });
    fireEvent.change(screen.getByLabelText('เนื้อหา'), { target: { value: 'เนื้อหาใหม่' } });
    fireEvent.click(screen.getByRole('button', { name: 'บันทึก' }));

    await waitFor(() => expect(mockCreate).toHaveBeenCalledWith({ name: 'ใหม่', category: '', messageType: 'text', content: 'เนื้อหาใหม่' }));
    await waitFor(() => expect(mockRefresh).toHaveBeenCalled());
  });

  it('opens the edit modal pre-filled and calls updateTemplateAction with the template id', async () => {
    render(<TemplatesClient templates={templates} categories={['ทั่วไป', 'โปรโมชั่น']} />);
    fireEvent.click(screen.getAllByRole('button', { name: 'แก้ไข' })[0]!);

    expect(screen.getByLabelText('ชื่อเทมเพลต')).toHaveValue('ทักทาย');
    fireEvent.click(screen.getByRole('button', { name: 'บันทึก' }));

    await waitFor(() =>
      expect(mockUpdate).toHaveBeenCalledWith(1, { name: 'ทักทาย', category: 'ทั่วไป', messageType: 'text', content: 'สวัสดีครับ' })
    );
  });

  it('deletes after confirm() and refreshes', async () => {
    const confirmSpy = jest.spyOn(window, 'confirm').mockReturnValue(true);
    render(<TemplatesClient templates={templates} categories={['ทั่วไป', 'โปรโมชั่น']} />);
    fireEvent.click(screen.getAllByRole('button', { name: 'ลบเทมเพลต' })[0]!);

    expect(confirmSpy).toHaveBeenCalledWith('ลบเทมเพลตนี้?');
    await waitFor(() => expect(mockDelete).toHaveBeenCalledWith(1));
    await waitFor(() => expect(mockRefresh).toHaveBeenCalled());
    confirmSpy.mockRestore();
  });

  it('does NOT delete when confirm() is cancelled', () => {
    const confirmSpy = jest.spyOn(window, 'confirm').mockReturnValue(false);
    render(<TemplatesClient templates={templates} categories={['ทั่วไป', 'โปรโมชั่น']} />);
    fireEvent.click(screen.getAllByRole('button', { name: 'ลบเทมเพลต' })[0]!);
    expect(mockDelete).not.toHaveBeenCalled();
    confirmSpy.mockRestore();
  });
});
