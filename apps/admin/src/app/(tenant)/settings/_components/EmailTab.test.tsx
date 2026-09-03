import { render, screen } from '@testing-library/react';

jest.mock('../../users/_lib/session', () => ({
  requireTenantPageContext: () => Promise.resolve({ db: {}, session: { currentBotId: 1 } }),
}));
jest.mock('next/navigation', () => ({
  redirect: jest.fn(),
}));
jest.mock('nodemailer', () => ({
  __esModule: true,
  default: { createTransport: jest.fn(() => ({ sendMail: jest.fn() })) },
}));

import { makeFakeTenantDb } from '../../users/testHelpers/fakeTenantDb';
import { EmailTab } from './EmailTab';

describe('EmailTab', () => {
  it('renders defaults (empty host, port 587, tls, "Notification") when no row exists yet', async () => {
    const { db } = makeFakeTenantDb(() => []);
    const element = await EmailTab({ db });
    render(element);

    expect(screen.getByPlaceholderText('smtp.gmail.com')).toHaveValue('');
    expect(screen.getByPlaceholderText('587')).toHaveValue(587);
    expect(screen.getByPlaceholderText('Notification')).toHaveValue('Notification');
  });

  it('loads and populates an existing email_settings row', async () => {
    const { db } = makeFakeTenantDb(() => [
      {
        id: 1,
        line_account_id: 1,
        smtp_host: 'smtp.gmail.com',
        smtp_port: 465,
        smtp_user: 'me@gmail.com',
        smtp_pass: 'secret',
        smtp_secure: 'ssl',
        from_email: 'noreply@example.com',
        from_name: 'Reya Pharmacy',
      },
    ]);
    const element = await EmailTab({ db });
    render(element);

    expect(screen.getByPlaceholderText('smtp.gmail.com')).toHaveValue('smtp.gmail.com');
    expect(screen.getByPlaceholderText('587')).toHaveValue(465);
    expect(screen.getByPlaceholderText('your@email.com')).toHaveValue('me@gmail.com');
    expect(screen.getByPlaceholderText('noreply@yourdomain.com')).toHaveValue('noreply@example.com');
    expect(screen.getByPlaceholderText('Notification')).toHaveValue('Reya Pharmacy');
  });

  it('renders the SMTP save form and the separate test-email form as two distinct <form>s', async () => {
    const { db } = makeFakeTenantDb(() => []);
    const element = await EmailTab({ db });
    const { container } = render(element);

    const forms = container.querySelectorAll('form');
    expect(forms).toHaveLength(2);
    expect(forms[1].querySelector('input[name="test_email"]')).toBeInTheDocument();
  });

  it('renders the three SMTP security options', async () => {
    const { db } = makeFakeTenantDb(() => []);
    const element = await EmailTab({ db });
    render(element);

    expect(screen.getByRole('option', { name: 'TLS (Port 587)' })).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'SSL (Port 465)' })).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'None (Port 25)' })).toBeInTheDocument();
  });
});
