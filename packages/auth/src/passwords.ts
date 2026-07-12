import bcrypt from 'bcryptjs';

/**
 * passwords.ts — read-only password verification. Nothing in this file (or
 * anywhere else in this package's login path) ever calls bcrypt.hash()/
 * bcryptjs.hash() on a plaintext password — admin_users.password and
 * platform_users.password_hash were written by PHP's
 * password_hash($plain, PASSWORD_DEFAULT) (bcrypt, `$2y$` prefix) and this
 * package only ever verifies against those existing hashes.
 */

/**
 * verifyLegacyPassword — thin bcryptjs.compare() wrapper.
 *
 * bcryptjs treats the `$2a$`, `$2b$`, and `$2y$` hash-prefix variants as
 * equivalent for compare() — all three are bcrypt; `$2y$` is PHP's
 * password_hash(..., PASSWORD_DEFAULT) prefix (what every admin_users.password
 * / platform_users.password_hash value in this codebase actually uses),
 * `$2a$`/`$2b$` are the OpenBSD/node-bcrypt prefixes bcryptjs itself
 * generates. This is verified in tests/passwords.test.ts against a REAL
 * PHP-generated `$2y$` fixture (`php -r 'echo password_hash(...);'`), not
 * assumed from bcryptjs's docs.
 */
export async function verifyLegacyPassword(plain: string, bcryptHash: string): Promise<boolean> {
  return bcrypt.compare(plain, bcryptHash);
}
