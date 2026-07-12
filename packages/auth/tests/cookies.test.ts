import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { PLATFORM_SESSION_COOKIE, TENANT_SESSION_COOKIE, sessionCookieName } from '../src/types';

describe('cookie name constants', () => {
  it('TENANT_SESSION_COOKIE is exactly "reya_sid"', () => {
    expect(TENANT_SESSION_COOKIE).toBe('reya_sid');
  });

  it('PLATFORM_SESSION_COOKIE is exactly "reya_platform_sid"', () => {
    expect(PLATFORM_SESSION_COOKIE).toBe('reya_platform_sid');
  });

  it('sessionCookieName("tenant") === TENANT_SESSION_COOKIE', () => {
    expect(sessionCookieName('tenant')).toBe(TENANT_SESSION_COOKIE);
  });

  it('sessionCookieName("platform") === PLATFORM_SESSION_COOKIE', () => {
    expect(sessionCookieName('platform')).toBe(PLATFORM_SESSION_COOKIE);
  });

  it('the two cookie names are distinct literals', () => {
    expect(TENANT_SESSION_COOKIE).not.toBe(PLATFORM_SESSION_COOKIE);
  });

  it("'reya_sid' / 'reya_platform_sid' string literals appear nowhere in src/ except types.ts", () => {
    const srcDir = join(__dirname, '..', 'src');
    const offenders: string[] = [];
    for (const filename of readdirSync(srcDir)) {
      if (filename === 'types.ts' || !filename.endsWith('.ts')) {
        continue;
      }
      const contents = readFileSync(join(srcDir, filename), 'utf8');
      if (contents.includes('reya_sid') || contents.includes('reya_platform_sid')) {
        offenders.push(filename);
      }
    }
    expect(offenders).toEqual([]);
  });
});
