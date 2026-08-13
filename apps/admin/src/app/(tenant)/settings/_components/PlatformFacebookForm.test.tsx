import { render, screen, fireEvent } from '@testing-library/react';

jest.mock('../../users/_lib/session', () => ({
  requireTenantPageContext: () => Promise.resolve({ db: {}, session: { currentBotId: 1 } }),
}));
jest.mock('next/navigation', () => ({
  redirect: jest.fn(),
}));

import { PlatformFacebookForm } from './PlatformFacebookForm';
import type { FacebookAccountView } from '../_lib/platform-queries';

function makeAccount(overrides: Partial<FacebookAccountView> = {}): FacebookAccountView {
  return {
    id: 3,
    name: 'CNY Page',
    pageId: '111',
    appId: 'appid',
    appSecret: 'appsecret',
    pageAccessToken: 'pagetoken',
    verifyToken: 'verifytoken',
    isActive: true,
    ...overrides,
  };
}

describe('PlatformFacebookForm', () => {
  it('renders a prefilled edit form (fb_id = account id) with Save/Test/Delete buttons', () => {
    render(<PlatformFacebookForm account={makeAccount()} />);

    expect(screen.getByDisplayValue('CNY Page')).toBeInTheDocument();
    expect(screen.getByDisplayValue('111')).toBeInTheDocument();
    const hidden = document.querySelector('input[name="fb_id"]') as HTMLInputElement;
    expect(hidden.value).toBe('3');

    expect(screen.getByRole('button', { name: /บันทึก/ })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /ทดสอบการเชื่อมต่อ/ })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /ลบ/ })).toBeInTheDocument();
  });

  it('renders an empty "add new" form (fb_id = 0) with only the Save/Add button, no Test/Delete', () => {
    render(<PlatformFacebookForm />);

    const hidden = document.querySelector('input[name="fb_id"]') as HTMLInputElement;
    expect(hidden.value).toBe('0');

    expect(screen.getByRole('button', { name: /เพิ่มเพจ/ })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /ทดสอบการเชื่อมต่อ/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /^ลบ/ })).not.toBeInTheDocument();
  });

  it('the "is_active" checkbox defaults to checked for a new account', () => {
    render(<PlatformFacebookForm />);
    const checkbox = document.querySelector('input[name="is_active"]') as HTMLInputElement;
    expect(checkbox.checked).toBe(true);
  });

  it('the "is_active" checkbox reflects the account\'s current state when editing', () => {
    render(<PlatformFacebookForm account={makeAccount({ isActive: false })} />);
    const checkbox = document.querySelector('input[name="is_active"]') as HTMLInputElement;
    expect(checkbox.checked).toBe(false);
  });

  it('prompts window.confirm before allowing the delete button to submit, and blocks submission when declined', () => {
    const confirmSpy = jest.spyOn(window, 'confirm').mockReturnValue(false);
    render(<PlatformFacebookForm account={makeAccount()} />);

    const deleteButton = screen.getByRole('button', { name: /ลบ/ });
    const event = fireEvent.click(deleteButton);

    expect(confirmSpy).toHaveBeenCalledWith('ลบการเชื่อมต่อเพจนี้?');
    // fireEvent.click returns false when preventDefault() was called on a cancelable event.
    expect(event).toBe(false);

    confirmSpy.mockRestore();
  });

  it('allows the delete submission through when window.confirm is accepted', () => {
    const confirmSpy = jest.spyOn(window, 'confirm').mockReturnValue(true);
    render(<PlatformFacebookForm account={makeAccount()} />);

    const deleteButton = screen.getByRole('button', { name: /ลบ/ });
    const event = fireEvent.click(deleteButton);

    expect(confirmSpy).toHaveBeenCalled();
    expect(event).toBe(true);

    confirmSpy.mockRestore();
  });
});
