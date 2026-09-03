import { render, screen, fireEvent, waitFor } from '@testing-library/react';

const mockRefresh = jest.fn();
jest.mock('next/navigation', () => ({
  useRouter: () => ({ refresh: mockRefresh }),
}));

const mockSave = jest.fn();
const mockDelete = jest.fn();
const mockAddHoliday = jest.fn();
const mockDeleteHoliday = jest.fn();
jest.mock('../actions', () => ({
  savePharmacistAction: (...args: unknown[]) => mockSave(...args),
  deletePharmacistAction: (...args: unknown[]) => mockDelete(...args),
  addHolidayAction: (...args: unknown[]) => mockAddHoliday(...args),
  deleteHolidayAction: (...args: unknown[]) => mockDeleteHoliday(...args),
}));

import { PharmacistsClient } from './PharmacistsClient';
import type { PharmacistRow } from '../queries';

const PHARMACISTS: PharmacistRow[] = [
  {
    id: 1,
    title: 'ภก.',
    name: 'สมชาย ใจดี',
    specialty: 'เภสัชกรคลินิก',
    licenseNo: 'LIC-001',
    hospital: 'รพ.เอ',
    bio: null,
    imageUrl: null,
    rating: 4.5,
    reviewCount: 10,
    consultationFee: 100,
    consultationDuration: 15,
    isAvailable: 1,
    isActive: 1,
    completedCount: 2,
    upcomingCount: 1,
    schedules: [{ id: 1, pharmacistId: 1, dayOfWeek: 1, startTime: '09:00:00', endTime: '17:00:00', isAvailable: 1 }],
    holidays: [{ id: 9, pharmacistId: 1, holidayDate: '2026-08-01', reason: 'ลาพักร้อน' }],
  },
];

beforeEach(() => {
  jest.clearAllMocks();
  mockSave.mockResolvedValue({ success: true, id: 1 });
  mockDelete.mockResolvedValue({ success: true });
  mockAddHoliday.mockResolvedValue({ success: true });
  mockDeleteHoliday.mockResolvedValue({ success: true });
});

describe('PharmacistsClient', () => {
  it('renders a card per pharmacist and the empty state when there are none', () => {
    render(<PharmacistsClient pharmacists={[]} />);
    expect(screen.getByText('ยังไม่มีเภสัชกร')).toBeInTheDocument();
  });

  it('opens the create modal on "+ เพิ่มเภสัชกร" and saves via savePharmacistAction', async () => {
    render(<PharmacistsClient pharmacists={[]} />);
    fireEvent.click(screen.getByRole('button', { name: '+ เพิ่มเภสัชกร' }));

    fireEvent.change(screen.getByLabelText('ชื่อ-นามสกุล *'), { target: { value: 'คนใหม่' } });
    fireEvent.click(screen.getByRole('button', { name: 'บันทึก' }));

    await waitFor(() => expect(mockSave).toHaveBeenCalledWith(expect.objectContaining({ id: undefined, name: 'คนใหม่' })));
    await waitFor(() => expect(mockRefresh).toHaveBeenCalled());
  });

  it('opens the edit modal pre-filled (including the schedule grid) and calls savePharmacistAction with the pharmacist id', async () => {
    render(<PharmacistsClient pharmacists={PHARMACISTS} />);
    fireEvent.click(screen.getByRole('button', { name: 'แก้ไข' }));

    expect(screen.getByLabelText('ชื่อ-นามสกุล *')).toHaveValue('สมชาย ใจดี');
    expect(screen.getByLabelText('จันทร์ เริ่ม')).toHaveValue('09:00');
    expect(screen.getByLabelText('จันทร์ สิ้นสุด')).toHaveValue('17:00');

    fireEvent.click(screen.getByRole('button', { name: 'บันทึก' }));

    await waitFor(() => expect(mockSave).toHaveBeenCalledWith(expect.objectContaining({ id: 1, name: 'สมชาย ใจดี' })));
  });

  it('deletes after confirm() and refreshes', async () => {
    const confirmSpy = jest.spyOn(window, 'confirm').mockReturnValue(true);
    render(<PharmacistsClient pharmacists={PHARMACISTS} />);
    fireEvent.click(screen.getByRole('button', { name: 'ลบเภสัชกร' }));

    expect(confirmSpy).toHaveBeenCalledWith('ยืนยันการลบ?');
    await waitFor(() => expect(mockDelete).toHaveBeenCalledWith(1));
    await waitFor(() => expect(mockRefresh).toHaveBeenCalled());
    confirmSpy.mockRestore();
  });

  it('does NOT delete when confirm() is cancelled', () => {
    const confirmSpy = jest.spyOn(window, 'confirm').mockReturnValue(false);
    render(<PharmacistsClient pharmacists={PHARMACISTS} />);
    fireEvent.click(screen.getByRole('button', { name: 'ลบเภสัชกร' }));
    expect(mockDelete).not.toHaveBeenCalled();
    confirmSpy.mockRestore();
  });

  it('alerts with the exact Thai guard message and does NOT refresh when delete is blocked', async () => {
    mockDelete.mockResolvedValue({ success: false, error: 'ไม่สามารถลบได้ เนื่องจากมีนัดหมายที่รอดำเนินการ' });
    const confirmSpy = jest.spyOn(window, 'confirm').mockReturnValue(true);
    const alertSpy = jest.spyOn(window, 'alert').mockImplementation(() => {});
    render(<PharmacistsClient pharmacists={PHARMACISTS} />);

    fireEvent.click(screen.getByRole('button', { name: 'ลบเภสัชกร' }));

    await waitFor(() => expect(alertSpy).toHaveBeenCalledWith('ไม่สามารถลบได้ เนื่องจากมีนัดหมายที่รอดำเนินการ'));
    expect(mockRefresh).not.toHaveBeenCalled();
    confirmSpy.mockRestore();
    alertSpy.mockRestore();
  });

  it('opens the holiday modal for the clicked pharmacist, shows existing holidays, and adds a new one', async () => {
    render(<PharmacistsClient pharmacists={PHARMACISTS} />);
    fireEvent.click(screen.getByRole('button', { name: 'วันหยุด' }));

    expect(screen.getByText('เภสัชกร: สมชาย ใจดี')).toBeInTheDocument();
    expect(screen.getByText('(ลาพักร้อน)')).toBeInTheDocument();

    fireEvent.change(screen.getByLabelText('วันที่หยุด'), { target: { value: '2026-09-01' } });
    fireEvent.click(screen.getByRole('button', { name: '+ เพิ่มวันหยุด' }));

    await waitFor(() => expect(mockAddHoliday).toHaveBeenCalledWith({ pharmacistId: 1, holidayDate: '2026-09-01', reason: '' }));
    await waitFor(() => expect(mockRefresh).toHaveBeenCalled());
  });

  it('deletes a holiday from the modal', async () => {
    render(<PharmacistsClient pharmacists={PHARMACISTS} />);
    fireEvent.click(screen.getByRole('button', { name: 'วันหยุด' }));
    fireEvent.click(screen.getByRole('button', { name: 'ลบวันหยุด' }));

    await waitFor(() => expect(mockDeleteHoliday).toHaveBeenCalledWith(9, 1));
    await waitFor(() => expect(mockRefresh).toHaveBeenCalled());
  });
});
