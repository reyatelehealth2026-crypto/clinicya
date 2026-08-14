const mockSendMessage = jest.fn();
const mockPushMessage = jest.fn();
jest.mock('@reya/line', () => ({
  sendMessage: (...args: unknown[]) => mockSendMessage(...args),
  pushMessage: (...args: unknown[]) => mockPushMessage(...args),
}));

import { notifyOrderByLine } from './lineNotify';

const OPTIONS = { channelAccessToken: 'token-abc' };

beforeEach(() => {
  jest.clearAllMocks();
});

describe('notifyOrderByLine — default dispatcher (real @reya/line exports)', () => {
  it('calls @reya/line sendMessage with the reply token when one is present', async () => {
    mockSendMessage.mockResolvedValue({ code: 200, method: 'reply', body: {} });

    const result = await notifyOrderByLine(
      { userId: 'U1', message: 'hi', replyToken: 'tok-123', tokenExpires: '2026-01-01 00:00:00' },
      OPTIONS
    );

    expect(mockSendMessage).toHaveBeenCalledWith(
      { userId: 'U1', messages: 'hi', replyToken: 'tok-123', tokenExpires: '2026-01-01 00:00:00', internalUserId: null },
      OPTIONS
    );
    expect(mockPushMessage).not.toHaveBeenCalled();
    expect(result).toEqual({ code: 200, method: 'reply', body: {} });
  });

  it('still routes through sendMessage (whose own internals fall back to push) when there is no reply token', async () => {
    mockSendMessage.mockResolvedValue({ code: 200, method: 'push', body: {} });

    const result = await notifyOrderByLine({ userId: 'U1', message: 'hi', replyToken: null, tokenExpires: null }, OPTIONS);

    expect(mockSendMessage).toHaveBeenCalledWith(
      expect.objectContaining({ replyToken: null, tokenExpires: null }),
      OPTIONS
    );
    expect(result.method).toBe('push');
  });
});

describe('notifyOrderByLine — method_exists($line, "sendMessage") === false branch', () => {
  it('falls back to calling pushMessage directly when the dispatcher has no sendMessage', async () => {
    mockPushMessage.mockResolvedValue({ code: 200, body: { ok: true } });

    const result = await notifyOrderByLine(
      { userId: 'U1', message: 'hi', replyToken: 'tok-123', tokenExpires: null },
      OPTIONS,
      { sendMessage: undefined, pushMessage: mockPushMessage }
    );

    expect(mockSendMessage).not.toHaveBeenCalled();
    expect(mockPushMessage).toHaveBeenCalledWith('U1', 'hi', OPTIONS);
    expect(result).toEqual({ code: 200, body: { ok: true }, method: 'push' });
  });
});
