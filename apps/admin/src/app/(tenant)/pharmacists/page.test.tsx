import { render, screen } from '@testing-library/react';

const mockRequireTenantPageContext = jest.fn();
jest.mock('../users/_lib/session', () => ({
  requireTenantPageContext: () => mockRequireTenantPageContext(),
}));

jest.mock('next/navigation', () => ({
  useRouter: () => ({ refresh: jest.fn() }),
}));

jest.mock('next/cache', () => ({
  revalidatePath: jest.fn(),
}));

import { makeFakeTenantDb } from '../users/testHelpers/fakeTenantDb';
import PharmacistsPage from './page';

function wireDb(queryImpl: (sqlText: string, params: unknown[]) => unknown) {
  const { db } = makeFakeTenantDb(queryImpl);
  mockRequireTenantPageContext.mockResolvedValue({ db });
}

const PHARMACIST_ROW = {
  id: 1,
  title: 'ภก.',
  name: 'สมชาย ใจดี',
  specialty: 'เภสัชกรคลินิก',
  licenseNo: 'LIC-001',
  hospital: 'รพ.เอ',
  bio: null,
  imageUrl: null,
  rating: '4.5',
  reviewCount: 12,
  consultationFee: '150.00',
  consultationDuration: 20,
  isAvailable: 1,
  isActive: 1,
  completedCount: 5,
  upcomingCount: 3,
};

describe('PharmacistsPage', () => {
  beforeEach(() => {
    jest.clearAllMocks();
  });

  it('renders one card per pharmacist with name/title, specialty, license number, and appointment counts', async () => {
    wireDb((sqlText) => {
      if (sqlText.includes('FROM pharmacists p')) return [PHARMACIST_ROW];
      if (sqlText.includes('FROM pharmacist_schedules')) {
        return [{ id: 1, pharmacistId: 1, dayOfWeek: 1, startTime: '09:00:00', endTime: '17:00:00', isAvailable: 1 }];
      }
      if (sqlText.includes('FROM pharmacist_holidays')) return [];
      return [];
    });

    const element = await PharmacistsPage();
    render(element);

    expect(screen.getByText('ภก.สมชาย ใจดี')).toBeInTheDocument();
    expect(screen.getByText('เภสัชกรคลินิก')).toBeInTheDocument();
    expect(screen.getByText('ใบอนุญาต: LIC-001')).toBeInTheDocument();
    expect(screen.getByText('3')).toBeInTheDocument(); // upcomingCount
    expect(screen.getByText('5')).toBeInTheDocument(); // completedCount
    expect(screen.getByText('4.5')).toBeInTheDocument(); // rating
    expect(screen.getByText('฿150')).toBeInTheDocument();
  });

  it('falls back to "เภสัชกรทั่วไป" when specialty is empty and hides the license line when license_no is empty', async () => {
    wireDb((sqlText) => {
      if (sqlText.includes('FROM pharmacists p')) {
        return [{ ...PHARMACIST_ROW, specialty: null, licenseNo: null }];
      }
      return [];
    });

    const element = await PharmacistsPage();
    render(element);

    expect(screen.getByText('เภสัชกรทั่วไป')).toBeInTheDocument();
    expect(screen.queryByText(/ใบอนุญาต:/)).not.toBeInTheDocument();
  });

  it('shows the empty state when there are no pharmacists', async () => {
    wireDb(() => []);
    const element = await PharmacistsPage();
    render(element);
    expect(screen.getByText('ยังไม่มีเภสัชกร')).toBeInTheDocument();
  });

  it('highlights only the scheduled day badges (data-day-active) per pharmacist_schedules.day_of_week', async () => {
    wireDb((sqlText) => {
      if (sqlText.includes('FROM pharmacists p')) return [PHARMACIST_ROW];
      if (sqlText.includes('FROM pharmacist_schedules')) {
        return [
          { id: 1, pharmacistId: 1, dayOfWeek: 1, startTime: '09:00:00', endTime: '17:00:00', isAvailable: 1 },
          { id: 2, pharmacistId: 1, dayOfWeek: 3, startTime: '09:00:00', endTime: '17:00:00', isAvailable: 1 },
        ];
      }
      return [];
    });

    const element = await PharmacistsPage();
    const { container } = render(element);

    const badges = container.querySelectorAll('[data-day-active]');
    expect(badges).toHaveLength(7);
    const activeStates = Array.from(badges).map((b) => b.getAttribute('data-day-active'));
    expect(activeStates).toEqual(['false', 'true', 'false', 'true', 'false', 'false', 'false']);
  });
});
