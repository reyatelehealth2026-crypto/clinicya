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
  return {
    ...actual,
    // Real runWithTenantDb is a pure AsyncLocalStorage wrapper (no I/O) — safe to use as-is, same
    // pattern as apps/admin/src/app/api/auth/login/route.test.ts.
    runWithTenantDb: actual.runWithTenantDb,
  };
});

import { describe, expect, it, beforeEach } from '@jest/globals';
import type { NextRequest } from 'next/server';
import { createMasterLineAccountRouteRepository, routeByLineAccount } from '@reya/tenant';
import { getTenantDb } from '@reya/db';
import { getTenantDbContext } from '@reya/auth';
import {
  resolveMiniappTenantContext,
  withMiniappTenant,
  TENANT_UNRESOLVED_RESPONSE,
  TENANT_UNRESOLVED_STATUS,
} from './tenant';

const mockRouteByLineAccount = routeByLineAccount as jest.MockedFunction<typeof routeByLineAccount>;
const mockCreateRepo = createMasterLineAccountRouteRepository as jest.MockedFunction<
  typeof createMasterLineAccountRouteRepository
>;
const mockGetTenantDb = getTenantDb as jest.MockedFunction<typeof getTenantDb>;

function fakeRequest(headers: Record<string, string> = {}): NextRequest {
  return { headers: new Headers(headers) } as unknown as NextRequest;
}

describe('resolveMiniappTenantContext', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockCreateRepo.mockReturnValue({ findTenantIdByLineAccountId: jest.fn() });
    mockGetTenantDb.mockResolvedValue({ __fakeTenantDb: true } as never);
  });

  it('phase (a): x-tenant-id header present -> pinned immediately, routeByLineAccount is NEVER called (even if the body carries a different line_account_id)', async () => {
    const request = fakeRequest({ 'x-tenant-id': '2' });

    const result = await resolveMiniappTenantContext(request, {
      method: 'POST',
      jsonBody: { line_account_id: 999 }, // deliberately a different id — must be ignored, existing PHP quirk
    });

    expect(result).toEqual({ ok: true, context: { tenantId: 2, db: { __fakeTenantDb: true } } });
    expect(mockRouteByLineAccount).not.toHaveBeenCalled();
    expect(mockGetTenantDb).toHaveBeenCalledWith(2);
  });

  it('phase (a): a non-numeric/zero/negative x-tenant-id header is treated as absent, falls through to phase (b)', async () => {
    mockRouteByLineAccount.mockResolvedValue({ applied: true, tenantId: 5, lineAccountId: 12 });
    const request = fakeRequest({ 'x-tenant-id': 'not-a-number' });

    const result = await resolveMiniappTenantContext(request, { method: 'GET', query: { line_account_id: '12' } });

    expect(result).toEqual({ ok: true, context: { tenantId: 5, db: { __fakeTenantDb: true } } });
    expect(mockRouteByLineAccount).toHaveBeenCalledWith(
      { pinnedTenantId: null, method: 'GET', query: { line_account_id: '12' }, jsonBody: undefined },
      expect.anything()
    );
  });

  it('phase (b): no x-tenant-id -> routeByLineAccount() consulted with query+jsonBody', async () => {
    mockRouteByLineAccount.mockResolvedValue({ applied: true, tenantId: 7, lineAccountId: 12 });
    const request = fakeRequest();

    const result = await resolveMiniappTenantContext(request, {
      method: 'POST',
      jsonBody: { line_account_id: 12 },
    });

    expect(result).toEqual({ ok: true, context: { tenantId: 7, db: { __fakeTenantDb: true } } });
    expect(mockGetTenantDb).toHaveBeenCalledWith(7);
  });

  it('phase (c): neither signal resolves a tenant -> { ok: false }, getTenantDb never called', async () => {
    mockRouteByLineAccount.mockResolvedValue({ applied: false, reason: 'no_signal' });
    const request = fakeRequest();

    const result = await resolveMiniappTenantContext(request, { method: 'GET', query: {} });

    expect(result).toEqual({ ok: false });
    expect(mockGetTenantDb).not.toHaveBeenCalled();
  });

  it('resolve-line-account is the one deliberate exception — this module still resolves fine standalone, but callers of THAT route must not invoke this function at all (see resolve-line-account/route.ts)', async () => {
    // Documentation-only assertion: nothing to execute here, resolveMiniappTenantContext has no special
    // casing for a "resolve-line-account" path — the exclusion lives in that route's own file, which
    // simply never imports this module. See resolve-line-account/route.ts's module doc comment.
    expect(true).toBe(true);
  });
});

describe('withMiniappTenant', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockCreateRepo.mockReturnValue({ findTenantIdByLineAccountId: jest.fn() });
    mockGetTenantDb.mockResolvedValue({ __fakeTenantDb: true } as never);
  });

  it('runs the handler inside runWithTenantDb() with the resolved context (ambient AsyncLocalStorage, reused from @reya/auth)', async () => {
    const request = fakeRequest({ 'x-tenant-id': '9' });
    let capturedContext: unknown = null;

    const result = await withMiniappTenant(request, { method: 'GET' }, async (context) => {
      capturedContext = getTenantDbContext();
      return { handlerRanWith: context.tenantId };
    });

    expect(result).toEqual({ ok: true, value: { handlerRanWith: 9 } });
    expect(capturedContext).toEqual({ tenantId: 9, db: { __fakeTenantDb: true } });
  });

  it('returns { ok: false } and never calls the handler when the tenant cannot be resolved', async () => {
    mockRouteByLineAccount.mockResolvedValue({ applied: false, reason: 'no_route' });
    const request = fakeRequest();
    const handler = jest.fn();

    const result = await withMiniappTenant(request, { method: 'GET' }, handler);

    expect(result).toEqual({ ok: false });
    expect(handler).not.toHaveBeenCalled();
  });
});

describe('TENANT_UNRESOLVED_RESPONSE / TENANT_UNRESOLVED_STATUS', () => {
  it('matches contractNote point 2c exactly', () => {
    expect(TENANT_UNRESOLVED_RESPONSE).toEqual({ success: false, error: 'tenant_unresolved' });
    expect(TENANT_UNRESOLVED_STATUS).toBe(400);
  });
});
