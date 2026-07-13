/**
 * @jest-environment node
 */
import { GET } from './route';

describe('GET /api/health', () => {
  it('returns 200 with servedBy=next and an ISO timestamp', async () => {
    const response = await GET();
    const body = await response.json();

    expect(response.status).toBe(200);
    expect(body.status).toBe('ok');
    expect(body.servedBy).toBe('next');
    expect(() => new Date(body.ts).toISOString()).not.toThrow();
    expect(new Date(body.ts).toISOString()).toBe(body.ts);
  });
});
