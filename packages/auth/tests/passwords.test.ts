import { describe, expect, it } from 'vitest';
import { verifyLegacyPassword } from '../src/passwords';

// Fixture generated with the REAL php binary in this container, not assumed:
//   php -r 'echo password_hash("reya-auth-test-fixture-only", PASSWORD_DEFAULT);'
// Obviously-fake test-only credential — never a real system password.
const FIXTURE_PLAINTEXT = 'reya-auth-test-fixture-only';
const FIXTURE_PHP_BCRYPT_HASH = '$2y$12$aO5GPKoAjqHQ6eH3u62u9OiyZSswNnS2hsQJUCwRrjxQ5CImjNIba';

describe('verifyLegacyPassword', () => {
  it('verifies a real PHP-generated password_hash(..., PASSWORD_DEFAULT) ($2y$) hash', async () => {
    await expect(verifyLegacyPassword(FIXTURE_PLAINTEXT, FIXTURE_PHP_BCRYPT_HASH)).resolves.toBe(true);
  });

  it('rejects the wrong password against the same $2y$ hash', async () => {
    await expect(verifyLegacyPassword('definitely-wrong', FIXTURE_PHP_BCRYPT_HASH)).resolves.toBe(false);
  });

  it('also verifies a $2b$-prefixed hash (bcryptjs-generated) for the same plaintext — proves $2a$/$2b$/$2y$ are treated as equivalent by compare()', async () => {
    const bcrypt = (await import('bcryptjs')).default;
    const bcryptjsHash = await bcrypt.hash(FIXTURE_PLAINTEXT, 10); // test-only use of hash() — never in the login path
    expect(bcryptjsHash.startsWith('$2b$') || bcryptjsHash.startsWith('$2a$')).toBe(true);
    await expect(verifyLegacyPassword(FIXTURE_PLAINTEXT, bcryptjsHash)).resolves.toBe(true);
  });

  it('never calls hash()/genSalt() itself — module surface only exposes verifyLegacyPassword', async () => {
    const mod = await import('../src/passwords');
    expect(Object.keys(mod)).toEqual(['verifyLegacyPassword']);
  });
});
