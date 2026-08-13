/**
 * @jest-environment node
 */
import { lineMarkAsRead, type LineMarkAsReadFetch, type LineMarkAsReadRequestInit } from './lineMarkAsRead';

/** Records every call an injected fake fetch receives, for request-shape assertions. */
function makeFakeFetch(status: number): { fetchImpl: LineMarkAsReadFetch; calls: Array<[string, LineMarkAsReadRequestInit]> } {
  const calls: Array<[string, LineMarkAsReadRequestInit]> = [];
  const fetchImpl: LineMarkAsReadFetch = async (url, init) => {
    calls.push([url, init]);
    return { status };
  };
  return { fetchImpl, calls };
}

describe('lineMarkAsRead — local port of classes/LineAPI.php::markAsRead()', () => {
  it('POSTs to https://api.line.me/v2/bot/chat/markAsRead with body {markAsReadToken} and an Authorization: Bearer <channelAccessToken> header — no real network call, exercised entirely via an injected fake fetch', async () => {
    const { fetchImpl, calls } = makeFakeFetch(200);

    const result = await lineMarkAsRead('token-abc-123', {
      channelAccessToken: 'secret-channel-token',
      fetchImpl,
    });

    expect(result).toEqual({ success: true });
    expect(calls).toHaveLength(1);
    const [url, init] = calls[0];
    expect(url).toBe('https://api.line.me/v2/bot/chat/markAsRead');
    expect(init.method).toBe('POST');
    expect(init.headers['Content-Type']).toBe('application/json');
    expect(init.headers.Authorization).toBe('Bearer secret-channel-token');
    expect(JSON.parse(init.body)).toEqual({ markAsReadToken: 'token-abc-123' });
  });

  it('success iff HTTP 200 — a non-200 status (e.g. 400) is a graceful {success:false, error:"HTTP <code>"}, not a thrown error', async () => {
    const { fetchImpl } = makeFakeFetch(400);

    const result = await lineMarkAsRead('token-abc', { channelAccessToken: 'tok', fetchImpl });

    expect(result).toEqual({ success: false, error: 'HTTP 400' });
  });

  it('a rejected fetchImpl (transport-level failure) resolves gracefully to {success:false, error:<message>} instead of throwing — mirrors curl_exec() returning false + curl_error() populated, never a PHP exception', async () => {
    const fetchImpl: LineMarkAsReadFetch = async () => {
      throw new Error('network unreachable');
    };

    const result = await lineMarkAsRead('token-abc', { channelAccessToken: 'tok', fetchImpl });

    expect(result).toEqual({ success: false, error: 'network unreachable' });
  });

  it.each([200, 500])('status=%d only ever reads .status off the fake response, no other property', async (status) => {
    const { fetchImpl } = makeFakeFetch(status);
    const result = await lineMarkAsRead('t', { channelAccessToken: 'tok', fetchImpl });
    expect(result.success).toBe(status === 200);
  });
});
