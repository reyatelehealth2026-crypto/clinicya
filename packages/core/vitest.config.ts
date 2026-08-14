import { resolve } from 'node:path';
import { defineConfig } from 'vitest/config';

// Alias workspace deps straight to source (same strategy as
// packages/tenant/vitest.config.ts) so `pnpm --filter @reya/core test` is
// green in isolation, with zero build-order dependency on @reya/db.
// tests-live/** is intentionally NOT included here — it's a separate,
// Docker-backed live gate invoked only via the `test:live` script (tsx),
// never picked up by `vitest run`/`pnpm turbo run test`.
export default defineConfig({
  test: {
    environment: 'node',
    include: ['tests/**/*.test.ts'],
    restoreMocks: true,
  },
  resolve: {
    alias: {
      '@reya/db': resolve(__dirname, '../db/src/index.ts'),
    },
  },
});
