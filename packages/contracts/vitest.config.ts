import { defineConfig } from 'vitest/config';

// Mirrors packages/tenant/vitest.config.ts's strategy: no workspace-dep aliasing needed here since
// @reya/contracts' only runtime dependency is zod (no @reya/* imports) — see src/index.ts's doc
// comment for why that isolation is deliberate (parity-testing artifacts must never gain a build-order
// dependency on the packages they're validating).
export default defineConfig({
  test: {
    environment: 'node',
    include: ['tests/**/*.test.ts'],
    restoreMocks: true,
  },
});
