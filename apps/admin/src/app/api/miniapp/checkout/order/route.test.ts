/**
 * @jest-environment node
 */
jest.mock('@reya/tenant', () => ({
  createMasterLineAccountRouteRepository: jest.fn(),
  routeByLineAccount: jest.fn(),
}));
jest.mock('@reya/db', () => ({
  getTenantDb: jest.fn(),
}));
jest.mock('@reya/auth', () => {
  const actual = jest.requireActual('@reya/auth');
  return { ...actual, runWithTenantDb: actual.runWithTenantDb };
});
jest.mock('./_lib/createOrder', () => ({
  handleCreateOrder: jest.fn(),
}));
jest.mock('./_lib/uploadSlip', () => ({
  handleUploadSlip: jest.fn(),
}));

import { getTenantDb } from '@reya/db';
import { routeByLineAccount } from '@reya/tenant';
import { handleCreateOrder } from './_lib/createOrder';
import { handleUploadSlip } from './_lib/uploadSlip';
import { OPTIONS, POST } from './route';

const mockGetTenantDb = getTenantDb as jest.MockedFunction<typeof getTenantDb>;
const mockRouteByLineAccount = routeByLineAccount as jest.MockedFunction<typeof routeByLineAccount>;
const mockCreateOrder = handleCreateOrder as jest.Mock;
const mockUploadSlip = handleUploadSlip as jest.Mock;

function setupTenant() {
  mockGetTenantDb.mockResolvedValue({} as never);
  mockRouteByLineAccount.mockResolvedValue({ applied: true, tenantId: 1, lineAccountId: 1 });
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('OPTIONS /api/miniapp/checkout/order', () => {
  it('answers 204 with CORS headers', () => {
    const response = OPTIONS();
    expect(response.status).toBe(204);
    expect(response.headers.get('Access-Control-Allow-Origin')).toBe('*');
  });
});

describe('POST — JSON body dispatch (action=create_order)', () => {
  it('dispatches to handleCreateOrder with the parsed JSON body', async () => {
    setupTenant();
    mockCreateOrder.mockResolvedValue({ status: 200, body: { success: true, message: 'Order created', order_id: 1 } });

    const request = new Request('https://mini.example.com/api/miniapp/checkout/order', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'create_order', line_user_id: 'U1', line_account_id: 1 }),
    }) as unknown as import('next/server').NextRequest;

    const response = await POST(request);
    const body = await response.json();

    expect(response.status).toBe(200);
    expect(body).toEqual({ success: true, message: 'Order created', order_id: 1 });
    expect(mockCreateOrder).toHaveBeenCalledTimes(1);
    expect(mockCreateOrder.mock.calls[0][1]).toMatchObject({ action: 'create_order', line_user_id: 'U1' });
    expect(mockUploadSlip).not.toHaveBeenCalled();
  });

  it('unknown action on a JSON POST -> Invalid action, HTTP 200', async () => {
    setupTenant();
    const request = new Request('https://mini.example.com/api/miniapp/checkout/order', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'not_real' }),
    }) as unknown as import('next/server').NextRequest;

    const response = await POST(request);
    const body = await response.json();
    expect(response.status).toBe(200);
    expect(body).toEqual({ success: false, message: 'Invalid action' });
  });

  it('an exception thrown by handleCreateOrder surfaces as {success:false, message}, HTTP 200 (outer try/catch)', async () => {
    setupTenant();
    mockCreateOrder.mockRejectedValue(new Error('boom'));
    const request = new Request('https://mini.example.com/api/miniapp/checkout/order', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'create_order' }),
    }) as unknown as import('next/server').NextRequest;

    const response = await POST(request);
    const body = await response.json();
    expect(response.status).toBe(200);
    expect(body).toEqual({ success: false, message: 'boom' });
  });
});

describe('POST — multipart/form-data dispatch (action=upload_slip)', () => {
  it('branches on Content-Type, parses via request.formData(), and dispatches to handleUploadSlip with the incoming origin', async () => {
    setupTenant();
    mockUploadSlip.mockResolvedValue({ status: 200, body: { success: true, message: 'Slip uploaded', image_url: 'https://x/y.jpg' } });

    const form = new FormData();
    form.append('action', 'upload_slip');
    form.append('order_id', '900');
    form.append('line_account_id', '1');
    form.append('slip', new File([new Uint8Array(10)], 'slip.jpg', { type: 'image/jpeg' }));

    const request = new Request('https://tenant-1234.re-ya.com/api/miniapp/checkout/order', {
      method: 'POST',
      body: form,
    }) as unknown as import('next/server').NextRequest;

    const response = await POST(request);
    const body = await response.json();

    expect(response.status).toBe(200);
    expect(body).toEqual({ success: true, message: 'Slip uploaded', image_url: 'https://x/y.jpg' });
    expect(mockUploadSlip).toHaveBeenCalledTimes(1);
    const [, form2, origin] = mockUploadSlip.mock.calls[0];
    expect(form2).toBeInstanceOf(FormData);
    expect(form2.get('order_id')).toBe('900');
    expect(origin).toBe('https://tenant-1234.re-ya.com');
    expect(mockCreateOrder).not.toHaveBeenCalled();
  });

  it('unknown action on a multipart POST -> Invalid action, HTTP 200', async () => {
    setupTenant();
    const form = new FormData();
    form.append('action', 'not_real');
    form.append('slip', new File([new Uint8Array(1)], 'x.jpg', { type: 'image/jpeg' }));

    const request = new Request('https://mini.example.com/api/miniapp/checkout/order', {
      method: 'POST',
      body: form,
    }) as unknown as import('next/server').NextRequest;

    const response = await POST(request);
    const body = await response.json();
    expect(response.status).toBe(200);
    expect(body).toEqual({ success: false, message: 'Invalid action' });
  });
});

describe('tenant resolution', () => {
  it('unresolved tenant -> {success:false, error:tenant_unresolved}, HTTP 400 (JSON branch)', async () => {
    mockRouteByLineAccount.mockResolvedValue({ applied: false, reason: 'no_signal' });
    const request = new Request('https://mini.example.com/api/miniapp/checkout/order', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'create_order' }),
    }) as unknown as import('next/server').NextRequest;

    const response = await POST(request);
    const body = await response.json();

    expect(response.status).toBe(400);
    expect(body).toEqual({ success: false, error: 'tenant_unresolved' });
    expect(mockCreateOrder).not.toHaveBeenCalled();
  });

  it('unresolved tenant -> HTTP 400 (multipart branch), without ever touching disk (handleUploadSlip never called)', async () => {
    mockRouteByLineAccount.mockResolvedValue({ applied: false, reason: 'no_signal' });
    const form = new FormData();
    form.append('action', 'upload_slip');
    const request = new Request('https://mini.example.com/api/miniapp/checkout/order', {
      method: 'POST',
      body: form,
    }) as unknown as import('next/server').NextRequest;

    const response = await POST(request);
    const body = await response.json();

    expect(response.status).toBe(400);
    expect(body).toEqual({ success: false, error: 'tenant_unresolved' });
    expect(mockUploadSlip).not.toHaveBeenCalled();
  });
});
