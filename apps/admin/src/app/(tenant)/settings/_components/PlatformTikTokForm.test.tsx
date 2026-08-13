import { render, screen, fireEvent } from '@testing-library/react';

jest.mock('../../users/_lib/session', () => ({
  requireTenantPageContext: () => Promise.resolve({ db: {}, session: { currentBotId: 1 } }),
}));
jest.mock('next/navigation', () => ({
  redirect: jest.fn(),
}));

import { PlatformTikTokForm } from './PlatformTikTokForm';
import type { TiktokAccountView } from '../_lib/platform-queries';

function makeAccount(overrides: Partial<TiktokAccountView> = {}): TiktokAccountView {
  return {
    id: 4,
    name: 'CNY Shop',
    shopId: 'shop1',
    appKey: 'appkey',
    appSecret: 'appsecret',
    accessToken: 'accesstoken',
    refreshToken: 'refreshtoken',
    shopCipher: 'ciph3r',
    isActive: true,
    ...overrides,
  };
}

describe('PlatformTikTokForm', () => {
  it('renders a prefilled edit form (tt_id = account id) with Save/Test/Delete buttons', () => {
    render(<PlatformTikTokForm account={makeAccount()} />);

    expect(screen.getByDisplayValue('CNY Shop')).toBeInTheDocument();
    expect(screen.getByDisplayValue('shop1')).toBeInTheDocument();
    const hidden = document.querySelector('input[name="tt_id"]') as HTMLInputElement;
    expect(hidden.value).toBe('4');

    expect(screen.getByRole('button', { name: /บันทึก/ })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /ทดสอบการเชื่อมต่อ/ })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /ลบ/ })).toBeInTheDocument();
  });

  it('renders an empty "add new" form (tt_id = 0) with only the Save/Add button, no Test/Delete', () => {
    render(<PlatformTikTokForm />);

    const hidden = document.querySelector('input[name="tt_id"]') as HTMLInputElement;
    expect(hidden.value).toBe('0');

    expect(screen.getByRole('button', { name: /เพิ่มร้าน/ })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /ทดสอบการเชื่อมต่อ/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^ลบ/ })).not.toBeInTheDocument();
  });

  it('prompts window.confirm (with the TikTok-specific copy) before allowing the delete button to submit', () => {
    const confirmSpy = jest.spyOn(window, 'confirm').mockReturnValue(false);
    render(<PlatformTikTokForm account={makeAccount()} />);

    const deleteButton = screen.getByRole('button', { name: /ลบ/ });
    const event = fireEvent.click(deleteButton);

    expect(confirmSpy).toHaveBeenCalledWith('ลบการเชื่อมต่อร้านนี้?');
    expect(event).toBe(false);

    confirmSpy.mockRestore();
  });
});
