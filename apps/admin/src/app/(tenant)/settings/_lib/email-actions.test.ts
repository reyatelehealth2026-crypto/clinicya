import { makeFakeTenantDb, type RecordedQuery } from '../../users/testHelpers/fakeTenantDb';

const mockRequireTenantPageContext = jest.fn();
jest.mock('../../users/_lib/session', () => ({
  requireTenantPageContext: () => mockRequireTenantPageContext(),
}));

const mockRedirect = jest.fn((url: string) => {
  throw new Error(`REDIRECT:${url}`);
});
jest.mock('next/navigation', () => ({
  redirect: (url: string) => mockRedirect(url),
}));

const mockSendMail = jest.fn();
const mockCreateTransport = jest.fn(() => ({ sendMail: mockSendMail }));
jest.mock('nodemailer', () => ({
  __esModule: true,
  default: { createTransport: (...args: unknown[]) => mockCreateTransport(...args) },
}));

import { saveEmailSettingsAction, sendTestEmailAction } from './email-actions';

function formData(fields: Record<string, string>): FormData {
  const fd = new FormData();
  for (const [k, v] of Object.entries(fields)) fd.set(k, v);
  return fd;
}

function wireFakeDb(queryImpl: (sqlText: string, params: unknown[]) => unknown): { queries: RecordedQuery[] } {
  const { db, queries } = makeFakeTenantDb(queryImpl);
  mockRequireTenantPageContext.mockResolvedValue({ db });
  return { queries };
}

const CONFIGURED_SMTP_ROW = {
  id: 1,
  line_account_id: 1,
  smtp_host: 'smtp.gmail.com',
  smtp_port: 587,
  smtp_user: 'me@gmail.com',
  smtp_pass: 'secret',
  smtp_secure: 'tls',
  from_email: 'noreply@example.com',
  from_name: 'Reya Pharmacy',
};

beforeEach(() => {
  jest.clearAllMocks();
  mockSendMail.mockResolvedValue({ accepted: ['someone@example.com'], rejected: [] });
});

describe('saveEmailSettingsAction', () => {
  it('INSERTs ... ON DUPLICATE KEY UPDATE with id=1 and redirects with a success ?message=', async () => {
    const { queries } = wireFakeDb(() => []);

    await expect(
      saveEmailSettingsAction(
        formData({
          smtp_host: 'smtp.gmail.com',
          smtp_port: '587',
          smtp_user: 'me@gmail.com',
          smtp_pass: 'secret',
          smtp_secure: 'tls',
          from_email: 'noreply@example.com',
          from_name: 'Reya Pharmacy',
        })
      )
    ).rejects.toThrow('REDIRECT:');

    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO email_settings'));
    expect(insertQuery?.sql).toContain('ON DUPLICATE KEY UPDATE');
    expect(insertQuery?.params).toEqual([
      'smtp.gmail.com',
      587,
      'me@gmail.com',
      'secret',
      'tls',
      'noreply@example.com',
      'Reya Pharmacy',
    ]);
    expect(mockRedirect).toHaveBeenCalledWith(`/settings?tab=email&message=${encodeURIComponent('บันทึกการตั้งค่า Email สำเร็จ')}`);
  });

  it('defaults smtp_port to 587 when the field is absent, matching (int) ($_POST[\'smtp_port\'] ?? 587)', async () => {
    const { queries } = wireFakeDb(() => []);
    await expect(saveEmailSettingsAction(formData({}))).rejects.toThrow('REDIRECT:');
    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO email_settings'));
    expect(insertQuery?.params?.[1]).toBe(587);
  });

  it('casts smtp_port with PHP (int)-cast semantics when the field IS present (empty string -> 0)', async () => {
    const { queries } = wireFakeDb(() => []);
    await expect(saveEmailSettingsAction(formData({ smtp_port: '' }))).rejects.toThrow('REDIRECT:');
    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO email_settings'));
    expect(insertQuery?.params?.[1]).toBe(0);
  });

  it('casts a non-numeric-prefixed smtp_port to 0 and a leading-numeric one to its prefix', async () => {
    const { queries } = wireFakeDb(() => []);
    await expect(saveEmailSettingsAction(formData({ smtp_port: '465abc' }))).rejects.toThrow('REDIRECT:');
    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO email_settings'));
    expect(insertQuery?.params?.[1]).toBe(465);
  });

  it('defaults from_name to "Notification" only when the field is absent', async () => {
    const { queries } = wireFakeDb(() => []);
    await expect(saveEmailSettingsAction(formData({}))).rejects.toThrow('REDIRECT:');
    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO email_settings'));
    expect(insertQuery?.params?.[6]).toBe('Notification');
  });

  it('defaults smtp_secure to "tls" when absent', async () => {
    const { queries } = wireFakeDb(() => []);
    await expect(saveEmailSettingsAction(formData({}))).rejects.toThrow('REDIRECT:');
    const insertQuery = queries.find((q) => q.sql.includes('INSERT INTO email_settings'));
    expect(insertQuery?.params?.[4]).toBe('tls');
  });

  it('redirects with a ?error= message when the write throws', async () => {
    wireFakeDb(() => {
      throw new Error('DB write failed');
    });
    await expect(saveEmailSettingsAction(formData({}))).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(`/settings?tab=email&error=${encodeURIComponent('เกิดข้อผิดพลาด: DB write failed')}`);
  });
});

describe('sendTestEmailAction', () => {
  it('rejects an empty test_email with the FILTER_VALIDATE_EMAIL-equivalent message, without touching SMTP', async () => {
    wireFakeDb(() => []);
    await expect(sendTestEmailAction(formData({ test_email: '' }))).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(`/settings?tab=email&error=${encodeURIComponent('กรุณาระบุ Email ที่ถูกต้อง')}`);
    expect(mockCreateTransport).not.toHaveBeenCalled();
  });

  it('rejects a malformed test_email (no @) with the same message', async () => {
    wireFakeDb(() => []);
    await expect(sendTestEmailAction(formData({ test_email: 'not-an-email' }))).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(`/settings?tab=email&error=${encodeURIComponent('กรุณาระบุ Email ที่ถูกต้อง')}`);
    expect(mockCreateTransport).not.toHaveBeenCalled();
  });

  it('treats an unconfigured SMTP host (no smtp_host row) as a send failure', async () => {
    wireFakeDb(() => []); // email_settings empty -> DEFAULT_EMAIL_SETTINGS.smtpHost === ''
    await expect(sendTestEmailAction(formData({ test_email: 'test@example.com' }))).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(
      `/settings?tab=email&error=${encodeURIComponent('ส่ง Email ไม่สำเร็จ - ตรวจสอบการตั้งค่า SMTP')}`
    );
    expect(mockCreateTransport).not.toHaveBeenCalled();
  });

  it('sends via nodemailer and redirects with a success ?message= on the happy path', async () => {
    wireFakeDb(() => [CONFIGURED_SMTP_ROW]);
    mockSendMail.mockResolvedValue({ accepted: ['test@example.com'], rejected: [] });

    await expect(sendTestEmailAction(formData({ test_email: 'test@example.com' }))).rejects.toThrow('REDIRECT:');

    expect(mockCreateTransport).toHaveBeenCalledWith(
      expect.objectContaining({ host: 'smtp.gmail.com', port: 587, secure: false })
    );
    expect(mockSendMail).toHaveBeenCalledWith(expect.objectContaining({ to: 'test@example.com' }));
    expect(mockRedirect).toHaveBeenCalledWith(
      `/settings?tab=email&message=${encodeURIComponent('ส่ง Email ทดสอบสำเร็จไปยัง test@example.com')}`
    );
  });

  it('redirects with the SMTP-failure message when sendMail rejects', async () => {
    wireFakeDb(() => [CONFIGURED_SMTP_ROW]);
    mockSendMail.mockRejectedValue(new Error('connection refused'));

    await expect(sendTestEmailAction(formData({ test_email: 'test@example.com' }))).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(
      `/settings?tab=email&error=${encodeURIComponent('ส่ง Email ไม่สำเร็จ - ตรวจสอบการตั้งค่า SMTP')}`
    );
  });

  it('redirects with the SMTP-failure message when sendMail resolves but rejects the recipient', async () => {
    wireFakeDb(() => [CONFIGURED_SMTP_ROW]);
    mockSendMail.mockResolvedValue({ accepted: [], rejected: ['test@example.com'] });

    await expect(sendTestEmailAction(formData({ test_email: 'test@example.com' }))).rejects.toThrow('REDIRECT:');
    expect(mockRedirect).toHaveBeenCalledWith(
      `/settings?tab=email&error=${encodeURIComponent('ส่ง Email ไม่สำเร็จ - ตรวจสอบการตั้งค่า SMTP')}`
    );
  });

  it('uses secure:true for smtp_secure="ssl"', async () => {
    wireFakeDb(() => [{ ...CONFIGURED_SMTP_ROW, smtp_secure: 'ssl', smtp_port: 465 }]);
    await expect(sendTestEmailAction(formData({ test_email: 'test@example.com' }))).rejects.toThrow('REDIRECT:');
    expect(mockCreateTransport).toHaveBeenCalledWith(expect.objectContaining({ secure: true, port: 465 }));
  });
});
