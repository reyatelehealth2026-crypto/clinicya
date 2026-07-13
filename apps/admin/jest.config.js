const nextJest = require('next/jest');

const createJestConfig = nextJest({
  // Provide the path to your Next.js app to load next.config.ts and .env files
  dir: './',
});

// Add any custom config to be passed to Jest
const customJestConfig = {
  setupFilesAfterEnv: ['<rootDir>/jest.setup.js'],
  testEnvironment: 'jsdom',
  moduleNameMapper: {
    // Handle module aliases (mirrors tsconfig.json paths)
    '^@/(.*)$': '<rootDir>/src/$1',
    // Alias straight to source (mirrors packages/tenant/vitest.config.ts's own alias strategy) so
    // `pnpm --filter admin test` is green without a build-order dependency on @reya/config/@reya/tenant/@reya/auth.
    '^@reya/config$': '<rootDir>/../../packages/config/src/index.ts',
    '^@reya/tenant$': '<rootDir>/../../packages/tenant/src/index.ts',
    '^@reya/db$': '<rootDir>/../../packages/db/src/index.ts',
    '^@reya/auth$': '<rootDir>/../../packages/auth/src/index.ts',
    '^@reya/contracts$': '<rootDir>/../../packages/contracts/src/index.ts',
  },
  testMatch: [
    '<rootDir>/src/**/__tests__/**/*.{js,jsx,ts,tsx}',
    '<rootDir>/src/**/*.{test,spec}.{js,jsx,ts,tsx}',
  ],
  testPathIgnorePatterns: ['<rootDir>/.next/', '<rootDir>/node_modules/'],
  // `standalone` output copies package.json into .next/standalone/apps/admin/ — without this,
  // jest-haste-map scans it too and warns of a naming collision with the real ./package.json.
  modulePathIgnorePatterns: ['<rootDir>/.next/'],
  // NOTE: 'kysely' (ESM-only, pulled in transitively via @reya/tenant/@reya/db/@reya/auth) needs
  // next/jest to actually transform it instead of leaving it as an untransformed external — that's
  // handled via next.config.ts's `transpilePackages: ['kysely']`, which next/jest reads directly
  // (transformIgnorePatterns here can only APPEND to next/jest's generated default, not replace it,
  // so a local override here can't undo the default's node_modules exclusion on its own).
};

// createJestConfig is exported this way to ensure that next/jest can load the Next.js config which is async
module.exports = createJestConfig(customJestConfig);
