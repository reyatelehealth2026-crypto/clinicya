import { render, screen, fireEvent } from '@testing-library/react';

jest.mock('../../users/_lib/session', () => ({
  requireTenantPageContext: () => Promise.resolve({ db: {}, session: { currentBotId: 1 } }),
}));
jest.mock('next/navigation', () => ({
  redirect: jest.fn(),
}));

import { WelcomeMessageForm } from './WelcomeMessageForm';
import type { WelcomeSettings } from '../_lib/welcome-queries';

function makeSettings(overrides: Partial<WelcomeSettings> = {}): WelcomeSettings {
  return {
    isEnabled: false,
    messageType: 'text',
    textContent: 'สวัสดีค่ะ',
    flexContent: '',
    ...overrides,
  };
}

describe('WelcomeMessageForm', () => {
  it('renders a form with id="welcomeForm" and a hidden tab=welcome field', () => {
    render(<WelcomeMessageForm settings={makeSettings()} />);
    const form = document.getElementById('welcomeForm');
    expect(form).toBeInTheDocument();
    expect(form?.tagName).toBe('FORM');
    const tabInput = form?.querySelector('input[name="tab"]') as HTMLInputElement;
    expect(tabInput.value).toBe('welcome');
  });

  it('shows the text section (not hidden) and hides the flex section when messageType is "text"', () => {
    render(<WelcomeMessageForm settings={makeSettings({ messageType: 'text' })} />);
    const textSection = screen.getByPlaceholderText('สวัสดีค่ะ ยินดีต้อนรับสู่ร้านของเรา...').closest('div');
    const flexSection = screen.getByPlaceholderText('{"type": "bubble", "body": {...}}').closest('div')?.parentElement?.parentElement;
    expect(textSection).not.toHaveClass('hidden');
    expect(flexSection).toHaveClass('hidden');
  });

  it('shows the flex section and hides the text section when messageType is "flex"', () => {
    render(<WelcomeMessageForm settings={makeSettings({ messageType: 'flex' })} />);
    const textSection = screen.getByPlaceholderText('สวัสดีค่ะ ยินดีต้อนรับสู่ร้านของเรา...').closest('div');
    const flexSection = screen.getByPlaceholderText('{"type": "bubble", "body": {...}}').closest('div')?.parentElement?.parentElement;
    expect(textSection).toHaveClass('hidden');
    expect(flexSection).not.toHaveClass('hidden');
  });

  it('toggles sections when clicking the message-type radios', () => {
    render(<WelcomeMessageForm settings={makeSettings({ messageType: 'text' })} />);
    const flexRadio = screen.getByRole('radio', { name: 'Flex Message' });
    fireEvent.click(flexRadio);

    const textSection = screen.getByPlaceholderText('สวัสดีค่ะ ยินดีต้อนรับสู่ร้านของเรา...').closest('div');
    expect(textSection).toHaveClass('hidden');

    const textRadio = screen.getByRole('radio', { name: 'ข้อความธรรมดา' });
    fireEvent.click(textRadio);
    expect(textSection).not.toHaveClass('hidden');
  });

  it('shows the "insert JSON" placeholder in the preview when flex_content is empty', () => {
    render(<WelcomeMessageForm settings={makeSettings({ messageType: 'flex', flexContent: '' })} />);
    expect(screen.getByText('ใส่ JSON เพื่อดูตัวอย่าง')).toBeInTheDocument();
  });

  it('renders a live preview of valid bubble JSON as the user types', () => {
    render(<WelcomeMessageForm settings={makeSettings({ messageType: 'flex', flexContent: '' })} />);
    const textarea = screen.getByPlaceholderText('{"type": "bubble", "body": {...}}');
    fireEvent.change(textarea, {
      target: { value: JSON.stringify({ type: 'bubble', body: { contents: [{ type: 'text', text: 'Preview Hello' }] } }) },
    });
    expect(screen.getByText('Preview Hello')).toBeInTheDocument();
  });

  it('shows an error message for invalid JSON in the preview', () => {
    render(<WelcomeMessageForm settings={makeSettings({ messageType: 'flex', flexContent: '' })} />);
    const textarea = screen.getByPlaceholderText('{"type": "bubble", "body": {...}}');
    fireEvent.change(textarea, { target: { value: '{not valid json' } });
    expect(screen.getByText(/JSON ไม่ถูกต้อง:/)).toBeInTheDocument();
  });

  it('shows the "bubble only" message for a non-bubble type', () => {
    render(<WelcomeMessageForm settings={makeSettings({ messageType: 'flex', flexContent: '' })} />);
    const textarea = screen.getByPlaceholderText('{"type": "bubble", "body": {...}}');
    fireEvent.change(textarea, { target: { value: JSON.stringify({ type: 'carousel' }) } });
    expect(screen.getByText('รองรับเฉพาะ type: bubble')).toBeInTheDocument();
  });

  it('loads the "welcome" template into the textarea and preview on click', () => {
    render(<WelcomeMessageForm settings={makeSettings({ messageType: 'flex', flexContent: '' })} />);
    fireEvent.click(screen.getByRole('button', { name: /Template ต้อนรับ/ }));

    const textarea = screen.getByPlaceholderText('{"type": "bubble", "body": {...}}') as HTMLTextAreaElement;
    expect(textarea.value).toContain('ยินดีต้อนรับ');
    expect(screen.getByText('สวัสดีค่ะ {name}')).toBeInTheDocument();
  });

  it('loads the "promo" template into the textarea and preview on click', () => {
    render(<WelcomeMessageForm settings={makeSettings({ messageType: 'flex', flexContent: '' })} />);
    fireEvent.click(screen.getByRole('button', { name: /Template โปรโมชั่น/ }));

    const textarea = screen.getByPlaceholderText('{"type": "bubble", "body": {...}}') as HTMLTextAreaElement;
    expect(textarea.value).toContain('โปรโมชั่นพิเศษ');
    expect(screen.getByText('ใช้โค้ด: WELCOME20')).toBeInTheDocument();
  });

  it('alerts ✅ for valid JSON on "ตรวจสอบ JSON"', () => {
    const alertSpy = jest.spyOn(window, 'alert').mockImplementation(() => {});
    render(<WelcomeMessageForm settings={makeSettings({ messageType: 'flex', flexContent: '{"type":"bubble"}' })} />);
    fireEvent.click(screen.getByRole('button', { name: /ตรวจสอบ JSON/ }));
    expect(alertSpy).toHaveBeenCalledWith('✅ JSON ถูกต้อง!');
    alertSpy.mockRestore();
  });

  it('alerts ❌ for invalid JSON on "ตรวจสอบ JSON"', () => {
    const alertSpy = jest.spyOn(window, 'alert').mockImplementation(() => {});
    render(<WelcomeMessageForm settings={makeSettings({ messageType: 'flex', flexContent: '{bad' })} />);
    fireEvent.click(screen.getByRole('button', { name: /ตรวจสอบ JSON/ }));
    expect(alertSpy.mock.calls[0]?.[0]).toContain('❌ JSON ไม่ถูกต้อง:');
    alertSpy.mockRestore();
  });

  it('pre-fills text_content and flex_content from the passed-in settings', () => {
    render(<WelcomeMessageForm settings={makeSettings({ textContent: 'ข้อความเดิม', flexContent: '{"type":"bubble"}' })} />);
    expect(screen.getByPlaceholderText('สวัสดีค่ะ ยินดีต้อนรับสู่ร้านของเรา...')).toHaveValue('ข้อความเดิม');
    expect(screen.getByPlaceholderText('{"type": "bubble", "body": {...}}')).toHaveValue('{"type":"bubble"}');
  });
});
