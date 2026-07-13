import { describe, expect, it, vi } from 'vitest';
import {
  extractLineAccountCandidate,
  isNumericCandidate,
  routeByLineAccount,
  type LineAccountRouteRepository,
  type RouteByLineAccountInput,
} from '../src/routeByLineAccount';

function repoResolving(map: Record<number, number>, touchLastSeen?: LineAccountRouteRepository['touchLastSeen']): LineAccountRouteRepository {
  return {
    async findTenantIdByLineAccountId(lineAccountId: number) {
      return map[lineAccountId] ?? null;
    },
    touchLastSeen,
  };
}

function baseInput(overrides: Partial<RouteByLineAccountInput> = {}): RouteByLineAccountInput {
  return { pinnedTenantId: null, method: 'GET', ...overrides };
}

describe('isNumericCandidate', () => {
  it('accepts integer and float strings, and numbers', () => {
    expect(isNumericCandidate('42')).toBe(true);
    expect(isNumericCandidate('3.14')).toBe(true);
    expect(isNumericCandidate(42)).toBe(true);
    expect(isNumericCandidate(' 42 ')).toBe(true);
  });

  it('rejects non-numeric strings, empty string, and non-string/number types', () => {
    expect(isNumericCandidate('abc')).toBe(false);
    expect(isNumericCandidate('')).toBe(false);
    expect(isNumericCandidate(null)).toBe(false);
    expect(isNumericCandidate(undefined)).toBe(false);
    expect(isNumericCandidate({})).toBe(false);
    expect(isNumericCandidate(['5'])).toBe(false);
  });
});

describe('extractLineAccountCandidate — precedence', () => {
  it('GET wins over POST wins over JSON body when all three are present', () => {
    const input = baseInput({
      method: 'POST',
      query: { line_account_id: '10' },
      body: { line_account_id: '20' },
      jsonBody: { line_account_id: '30' },
    });
    expect(extractLineAccountCandidate(input)).toBe('10');
  });

  it('falls back to POST when GET has no signal at all', () => {
    const input = baseInput({ method: 'POST', body: { account: '20' }, jsonBody: { account: '30' } });
    expect(extractLineAccountCandidate(input)).toBe('20');
  });

  it('falls back to the JSON body only when method is POST and GET+POST are both empty', () => {
    const input = baseInput({ method: 'POST', jsonBody: { la: '30' } });
    expect(extractLineAccountCandidate(input)).toBe('30');
  });

  it('ignores the JSON body entirely when method is GET, even if present', () => {
    const input = baseInput({ method: 'GET', jsonBody: { la: '30' } });
    expect(extractLineAccountCandidate(input)).toBeNull();
  });

  it('within one source, line_account_id beats la beats account', () => {
    const input = baseInput({ query: { line_account_id: '1', la: '2', account: '3' } });
    expect(extractLineAccountCandidate(input)).toBe('1');
  });

  it(
    'PHP ?? quirk: a SET-but-empty key wins within its source (does not fall through to the ' +
      'next key in the same source) — the empty result then abandons the whole source for the next one',
    () => {
      // query.line_account_id is *set* (even though empty) -> PHP's `??` chain stops there,
      // never reaching `la`. The resulting '' candidate is empty, so GET as a whole is
      // abandoned in favour of POST — NOT "fall through to la within GET".
      const input = baseInput({ method: 'GET', query: { line_account_id: '', la: '5' } });
      expect(extractLineAccountCandidate(input)).toBeNull();
    }
  );

  it('the same quirk lets POST recover a later key once GET is fully empty', () => {
    const input = baseInput({
      method: 'POST',
      query: { line_account_id: '' }, // set-but-empty -> GET source abandoned
      body: { line_account_id: '', la: '7' }, // same quirk inside POST -> POST source also abandoned
      jsonBody: { la: '9' },
    });
    expect(extractLineAccountCandidate(input)).toBe('9');
  });
});

describe('routeByLineAccount', () => {
  it('no-op when a tenant is already pinned, even with a valid signal present', async () => {
    const repo = repoResolving({ 5: 100 });
    const result = await routeByLineAccount(
      baseInput({ pinnedTenantId: 7, query: { account: '5' } }),
      repo
    );
    expect(result).toEqual({ applied: false, reason: 'already_pinned' });
  });

  it('no-op when there is no signal at all', async () => {
    const result = await routeByLineAccount(baseInput(), repoResolving({}));
    expect(result).toEqual({ applied: false, reason: 'no_signal' });
  });

  it('no-op for a non-numeric candidate', async () => {
    const result = await routeByLineAccount(baseInput({ query: { account: 'abc' } }), repoResolving({}));
    expect(result).toEqual({ applied: false, reason: 'not_numeric' });
  });

  it('no-op for candidate <= 0 ("0")', async () => {
    const result = await routeByLineAccount(baseInput({ query: { account: '0' } }), repoResolving({}));
    expect(result).toEqual({ applied: false, reason: 'not_positive' });
  });

  it('no-op for a negative candidate', async () => {
    const result = await routeByLineAccount(baseInput({ query: { account: '-5' } }), repoResolving({}));
    expect(result).toEqual({ applied: false, reason: 'not_positive' });
  });

  it('no-op when the repository has no route for the line_account_id', async () => {
    const result = await routeByLineAccount(baseInput({ query: { account: '999' } }), repoResolving({}));
    expect(result).toEqual({ applied: false, reason: 'no_route' });
  });

  it('no-op (fail-safe) when the repository throws', async () => {
    const repo: LineAccountRouteRepository = {
      async findTenantIdByLineAccountId() {
        throw new Error('db down');
      },
    };
    const result = await routeByLineAccount(baseInput({ query: { account: '5' } }), repo);
    expect(result).toEqual({ applied: false, reason: 'lookup_error' });
  });

  it('applies the route on a valid, resolvable candidate and calls touchLastSeen', async () => {
    const touchLastSeen = vi.fn().mockResolvedValue(undefined);
    const repo = repoResolving({ 5: 100 }, touchLastSeen);

    const result = await routeByLineAccount(baseInput({ query: { account: '5' } }), repo);

    expect(result).toEqual({ applied: true, tenantId: 100, lineAccountId: 5 });
    expect(touchLastSeen).toHaveBeenCalledWith(5, 100);
  });

  it('a touchLastSeen failure is swallowed and does not affect the result', async () => {
    const touchLastSeen = vi.fn().mockRejectedValue(new Error('telemetry unavailable'));
    const repo = repoResolving({ 5: 100 }, touchLastSeen);

    const result = await routeByLineAccount(baseInput({ query: { account: '5' } }), repo);

    expect(result).toEqual({ applied: true, tenantId: 100, lineAccountId: 5 });
  });

  it('accepts a float-looking candidate and truncates toward zero (mirrors PHP (int) cast)', async () => {
    const repo = repoResolving({ 5: 100 });
    const result = await routeByLineAccount(baseInput({ query: { account: '5.9' } }), repo);
    expect(result).toEqual({ applied: true, tenantId: 100, lineAccountId: 5 });
  });
});
