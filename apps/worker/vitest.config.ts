import { resolve } from 'node:path';
import { defineConfig } from 'vitest/config';

// Alias workspace deps straight to source so `pnpm --filter worker test` is
// green in isolation, with zero build-order dependency on @reya/config or
// @reya/db — same pattern as packages/tenant/vitest.config.ts and
// packages/auth/vitest.config.ts. Tests never touch these modules' real
// network/DB code paths: they `vi.mock('mysql2', ...)` / `vi.mock('ioredis',
// ...)` / `vi.mock('bullmq', ...)` exactly like packages/db and
// packages/tenant's own tests do, so the aliased-to-source @reya/db module
// resolves against a fake mysql2 pool, never a real socket.
export default defineConfig({
  test: {
    environment: 'node',
    include: ['tests/**/*.test.ts'],
    restoreMocks: true,
  },
  resolve: {
    alias: {
      '@reya/config': resolve(__dirname, '../../packages/config/src/index.ts'),
      '@reya/db': resolve(__dirname, '../../packages/db/src/index.ts'),
    },
  },
});
