import type { NextConfig } from 'next';

/**
 * next.config.ts — apps/admin (plan §1.1/§1.5).
 *
 * output: 'standalone' — infra/php's Docker image work (later phase) expects
 * a self-contained `.next/standalone` bundle it can COPY into a minimal
 * runtime image, same pattern as backend/'s and line-mini-app's containers.
 */
const nextConfig: NextConfig = {
  output: 'standalone',
  // 'kysely' (pulled in transitively via @reya/tenant/@reya/db/@reya/auth) ships ESM-only
  // (package.json "type":"module", no CJS build) — transpilePackages makes both Next's own
  // bundler AND next/jest's SWC transform handle it instead of leaving it as an untransformed
  // external, which otherwise breaks `next/jest`'s default CJS-only transform in tests.
  transpilePackages: ['kysely'],
};

export default nextConfig;
