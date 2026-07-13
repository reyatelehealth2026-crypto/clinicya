/**
 * @jest-environment node
 *
 * Route/proxy code needs the Web-standard Request/Response globals that
 * `next/server` builds on — jsdom (this project's default test environment,
 * see jest.config.js) doesn't provide those. Node 18+'s built-in fetch
 * globals do, hence the per-file override (Next's own documented pattern
 * for testing Route Handlers/middleware under Jest).
 */
import { NextRequest } from 'next/server';

// Mock the wrapper, NOT @reya/tenant directly — proxy.ts's own job is purely
// the ResolveTenantResult -> HTTP response mapping; resolveRequestTenant's
// four-outcome-kind coverage already lives in resolveRequestTenant.test.ts.
jest.mock('./lib/tenant/resolveRequestTenant');

import { resolveRequestTenant } from './lib/tenant/resolveRequestTenant';
import { proxy } from './proxy';

const mockResolveRequestTenant = resolveRequestTenant as jest.MockedFunction<typeof resolveRequestTenant>;

function makeRequest(url: string): NextRequest {
  return new NextRequest(new Request(url));
}

describe('proxy', () => {
  afterEach(() => {
    jest.resetAllMocks();
  });

  it("kind: 'tenant' -> sets x-tenant-* request headers and continues", async () => {
    mockResolveRequestTenant.mockResolvedValue({
      kind: 'tenant',
      tenantId: 7,
      slug: 'tenant-0007',
      isRoot: false,
      demoMode: true,
    });

    const response = await proxy(makeRequest('https://tenant-0007.re-ya.com/dashboard'));

    expect(response.headers.get('x-middleware-request-x-tenant-id')).toBe('7');
    expect(response.headers.get('x-middleware-request-x-tenant-slug')).toBe('tenant-0007');
    expect(response.headers.get('x-middleware-request-x-tenant-is-root')).toBe('false');
    expect(response.headers.get('x-middleware-request-x-tenant-demo-mode')).toBe('true');
  });

  it("kind: 'not_found' -> 404 with Thai copy", async () => {
    mockResolveRequestTenant.mockResolvedValue({ kind: 'not_found', slug: 'tenant-9999' });

    const response = await proxy(makeRequest('https://tenant-9999.re-ya.com/'));
    const body = await response.text();

    expect(response.status).toBe(404);
    expect(body).toContain('ไม่พบร้านค้านี้');
  });

  it("kind: 'suspended' -> 503 with Thai copy", async () => {
    mockResolveRequestTenant.mockResolvedValue({
      kind: 'suspended',
      tenantId: 3,
      slug: 'tenant-0003',
      displayName: 'Suspended Pharmacy',
      status: 'suspended',
    });

    const response = await proxy(makeRequest('https://tenant-0003.re-ya.com/'));
    const body = await response.text();

    expect(response.status).toBe(503);
    expect(body).toContain('ระงับการใช้งานชั่วคราว');
  });

  it("kind: 'none' -> passes through untouched", async () => {
    mockResolveRequestTenant.mockResolvedValue({ kind: 'none', reason: 'reserved_or_no_match' });

    const response = await proxy(makeRequest('https://www.re-ya.com/'));

    expect(response.status).toBe(200);
    expect(response.headers.get('x-tenant-id')).toBeNull();
  });
});
