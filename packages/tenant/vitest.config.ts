import { resolve } from 'node:path';
import { defineConfig } from 'vitest/config';

// Alias workspace deps straight to source so `pnpm --filter @reya/tenant test`
// is green in isolation, with zero build-order dependency on @reya/config or
// @reya/db.
export default defineConfig({
  test: {
    environment: 'node',
    include: ['tests/**/*.test.ts'],
    restoreMocks: true,
  },
  resolve: {
    alias: {
      '@reya/config': resolve(__dirname, '../config/src/index.ts'),
      '@reya/db': resolve(__dirname, '../db/src/index.ts'),
    },
  },
});
