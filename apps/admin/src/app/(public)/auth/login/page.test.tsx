import { render, screen } from '@testing-library/react';

jest.mock('next/headers', () => ({
  headers: jest.fn(),
}));

import { headers } from 'next/headers';
import LoginPage from './page';

const mockHeaders = headers as jest.MockedFunction<typeof headers>;

function fakeHeaders(entries: Record<string, string>) {
  return {
    get: (key: string) => entries[key] ?? null,
  } as unknown as Awaited<ReturnType<typeof headers>>;
}

describe('LoginPage', () => {
  it('contains both a Thai and an English string, and posts to /api/auth/login', async () => {
    mockHeaders.mockResolvedValue(fakeHeaders({}));

    const element = await LoginPage({ searchParams: Promise.resolve({}) });
    render(element);

    expect(screen.getByRole('heading', { name: 'เข้าสู่ระบบ' })).toBeInTheDocument();
    expect(screen.getByText('Sign in to Reya Admin')).toBeInTheDocument();

    const form = document.querySelector('form');
    expect(form).not.toBeNull();
    expect(form).toHaveAttribute('action', '/api/auth/login');
    expect(form).toHaveAttribute('method', 'POST');
  });

  it('defaults to platform realm (email field) when no x-tenant-id header is present', async () => {
    mockHeaders.mockResolvedValue(fakeHeaders({}));

    const element = await LoginPage({ searchParams: Promise.resolve({}) });
    render(element);

    expect(document.querySelector('input[name="email"]')).not.toBeNull();
    expect(document.querySelector('input[name="username"]')).toBeNull();
    expect(document.querySelector('input[name="realm"]')).toHaveValue('platform');
  });

  it('defaults to tenant realm (username field) when x-tenant-id is present', async () => {
    mockHeaders.mockResolvedValue(fakeHeaders({ 'x-tenant-id': '2' }));

    const element = await LoginPage({ searchParams: Promise.resolve({}) });
    render(element);

    expect(document.querySelector('input[name="username"]')).not.toBeNull();
    expect(document.querySelector('input[name="email"]')).toBeNull();
    expect(document.querySelector('input[name="realm"]')).toHaveValue('tenant');
  });

  it('renders a bilingual error message for a known error code', async () => {
    mockHeaders.mockResolvedValue(fakeHeaders({}));

    const element = await LoginPage({ searchParams: Promise.resolve({ error: 'invalid_credentials' }) });
    render(element);

    expect(screen.getByText('ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง')).toBeInTheDocument();
    expect(screen.getByText('Incorrect username/email or password.')).toBeInTheDocument();
  });
});
