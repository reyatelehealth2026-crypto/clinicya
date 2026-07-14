import { defineConfig } from 'vitest/config';

// Mirrors packages/contracts/vitest.config.ts's strategy: @reya/line's fixture-round-trip
// tests (flex.test.ts) have no @reya/* runtime dependency, so no workspace-dep aliasing is
// needed here.
export default defineConfig({
  test: {
    environment: 'node',
    include: ['tests/**/*.test.ts'],
    restoreMocks: true,
  },
});
