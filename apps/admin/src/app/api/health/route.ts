import { NextResponse } from 'next/server';

/**
 * GET /api/health — plain liveness probe for apps/admin itself. `servedBy`
 * lets the nginx strangler edge (plan §1.5) / infra health checks confirm a
 * response actually came from the Next app, not a PHP fallback.
 */
export async function GET(): Promise<NextResponse> {
  return NextResponse.json(
    {
      status: 'ok',
      servedBy: 'next',
      ts: new Date().toISOString(),
    },
    { status: 200 }
  );
}
