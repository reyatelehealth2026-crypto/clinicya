import { resolve } from 'node:path';
import { defineConfig } from 'vitest/config';

// Alias workspace deps straight to source so `pnpm --filter @reya/db test`
// is green in isolation, with zero build-order dependency on @reya/config.
export default defineConfig({
  test: {
    environment: 'node',
    include: ['tests/**/*.test.ts'],
    restoreMocks: true,
  },
  resolve: {
    alias: {
      '@reya/config': resolve(__dirname, '../config/src/index.ts'),
    },
  },
});
