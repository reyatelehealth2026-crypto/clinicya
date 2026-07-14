import { render, screen } from '@testing-library/react';
import type { MessageRow } from '../../../../api/inbox/messages/_lib/query';
import { MessageBubble } from './MessageBubble';

function msg(overrides: Partial<MessageRow> = {}): MessageRow {
  return {
    id: 1,
    user_id: 7,
    direction: 'incoming',
    message_type: 'text',
    content: 'hello',
    is_read: 1,
    sent_by: null,
    created_at: new Date(2026, 6, 14, 9, 5, 0), // local-getters round trip — see MessageBubble.tsx's formatMessageTime doc
    ...overrides,
  };
}

describe('MessageBubble — layout/meta', () => {
  it('incoming message: justify-start, shows the avatar, no sender badge', () => {
    const { container } = render(<MessageBubble message={msg({ direction: 'incoming' })} pictureUrl="https://x/a.png" />);
    expect(container.querySelector('.message-item')).toHaveClass('justify-start');
    expect(container.querySelector('img[alt=""]')).toHaveAttribute('src', 'https://x/a.png');
  });

  it('outgoing message: justify-end, no avatar, shows the Admin sender badge by default', () => {
    const { container } = render(<MessageBubble message={msg({ direction: 'outgoing', sent_by: null })} />);
    expect(container.querySelector('.message-item')).toHaveClass('justify-end');
    // avatar <img> only renders for incoming messages
    const wrapper = container.querySelector('.message-item') as HTMLElement;
    expect(wrapper.querySelector('img.rounded-full')).not.toBeInTheDocument();
    expect(screen.getByText('Admin')).toBeInTheDocument();
  });

  it('formats the meta time as HH:MM', () => {
    render(<MessageBubble message={msg({ created_at: new Date(2026, 6, 14, 9, 5, 0) })} />);
    expect(screen.getByText('09:05')).toBeInTheDocument();
  });

  it('accepts a JSON-wire string created_at ("YYYY-MM-DD HH:MM:SS") identically to a Date', () => {
    render(<MessageBubble message={{ ...msg(), created_at: '2026-07-14 09:05:00' } as unknown as MessageRow} />);
    expect(screen.getByText('09:05')).toBeInTheDocument();
  });

  it.each([
    ['admin:สมศรี', 'สมศรี'],
    ['ai', 'AI'],
    ['ai:gemini', 'AI'],
    ['bot', 'Bot'],
    ['system:dispense', 'Bot'],
    ['someone_else', 'someone_else'],
  ])('sender badge for sent_by=%s -> %s', (sentBy, expected) => {
    render(<MessageBubble message={msg({ direction: 'outgoing', sent_by: sentBy })} />);
    expect(screen.getByText(expected)).toBeInTheDocument();
  });
});

describe('MessageBubble — text', () => {
  it('plain text renders in a chat bubble with correct incoming/outgoing class', () => {
    const { container } = render(<MessageBubble message={msg({ direction: 'incoming', content: 'Hi there' })} />);
    expect(container.querySelector('.chat-bubble.chat-incoming')).toHaveTextContent('Hi there');
  });

  it('preserves newlines as <br> (nl2br equivalent)', () => {
    const { container } = render(<MessageBubble message={msg({ content: 'line1\nline2' })} />);
    const bubble = container.querySelector('.chat-bubble') as HTMLElement;
    expect(bubble.innerHTML).toContain('line1<br>line2');
  });

  it('extracts text from an embedded LINE {type:"text", text} JSON payload', () => {
    render(<MessageBubble message={msg({ content: JSON.stringify({ type: 'text', text: 'From JSON' }) })} />);
    expect(screen.getByText('From JSON')).toBeInTheDocument();
  });

  it('renders the raw content when JSON.parse succeeds but is not a LINE text object', () => {
    const raw = JSON.stringify({ foo: 'bar' });
    render(<MessageBubble message={msg({ content: raw })} />);
    expect(screen.getByText(raw)).toBeInTheDocument();
  });

  it('renders a quick-reply preview strip when the payload has quickReply.items', () => {
    render(
      <MessageBubble
        message={msg({
          content: JSON.stringify({
            type: 'text',
            text: 'Pick one',
            quickReply: { items: [{ action: { type: 'message', label: 'ตัวเลือก 1' } }] },
          }),
        })}
      />
    );
    expect(screen.getByText('Pick one')).toBeInTheDocument();
    expect(screen.getByText(/ตัวเลือก 1/)).toBeInTheDocument();
  });

  it('does not render a quick-reply strip when items is missing/empty', () => {
    const { container } = render(<MessageBubble message={msg({ content: JSON.stringify({ type: 'text', text: 'x' }) })} />);
    expect(container.querySelector('.quick-reply-preview')).not.toBeInTheDocument();
  });
});

describe('MessageBubble — text-as-video (CLAUDE.md gap #1)', () => {
  it('a text message whose content is an /uploads/line_videos/ path renders as video, not text', () => {
    const { container } = render(
      <MessageBubble message={msg({ message_type: 'text', content: '/uploads/line_videos/clip123.mp4' })} />
    );
    expect(container.querySelector('.video-message')).toBeInTheDocument();
    expect(container.querySelector('.chat-bubble')).not.toBeInTheDocument();
    // A relative /uploads/... path is not `^https?://`, so it falls through video's
    // OWN src-resolution (same function, applied AFTER the type override) to the
    // final "proxy the raw content as an id" branch — literal PHP behavior
    // (inbox-v2.php lines 3396-3403), not a bug introduced by this port.
    expect(container.querySelector('video source')).toHaveAttribute(
      'src',
      'api/line_content.php?id=/uploads/line_videos/clip123.mp4'
    );
  });

  it.each(['clip.mp4', 'clip.MOV', 'clip.avi', 'clip.webm'])(
    'a text message ending in a video extension (%s) renders as video',
    (filename) => {
      const { container } = render(<MessageBubble message={msg({ message_type: 'text', content: `https://cdn/${filename}` })} />);
      expect(container.querySelector('.video-message')).toBeInTheDocument();
    }
  );

  it('a text message that merely CONTAINS "video" but is not a video path/extension stays text', () => {
    const { container } = render(<MessageBubble message={msg({ message_type: 'text', content: 'I watched a video today' })} />);
    expect(container.querySelector('.chat-bubble')).toBeInTheDocument();
    expect(container.querySelector('.video-message')).not.toBeInTheDocument();
  });
});

describe('MessageBubble — image', () => {
  it('an ID: NNN content resolves through the line_content.php proxy', () => {
    const { container } = render(<MessageBubble message={msg({ message_type: 'image', content: 'ID:123' })} />);
    expect(container.querySelector('img.rounded-xl')).toHaveAttribute('src', 'api/line_content.php?id=123');
  });

  it('a bare (non-http) id proxies with the raw content as the id', () => {
    const { container } = render(<MessageBubble message={msg({ message_type: 'image', content: 'abc123' })} />);
    expect(container.querySelector('img.rounded-xl')).toHaveAttribute('src', 'api/line_content.php?id=abc123');
  });

  it('an absolute http(s) URL passes through unchanged', () => {
    const { container } = render(<MessageBubble message={msg({ message_type: 'image', content: 'https://cdn.example.com/a.png' })} />);
    expect(container.querySelector('img.rounded-xl')).toHaveAttribute('src', 'https://cdn.example.com/a.png');
  });
});

describe('MessageBubble — sticker', () => {
  it('JSON {"stickerId": N} renders the LINE stickershop image', () => {
    const { container } = render(<MessageBubble message={msg({ message_type: 'sticker', content: JSON.stringify({ stickerId: 52002734 }) })} />);
    expect(container.querySelector('img.w-20')).toHaveAttribute(
      'src',
      'https://stickershop.line-scdn.net/stickershop/v1/sticker/52002734/android/sticker.png'
    );
  });

  it('legacy "Sticker: N" text form is also recognized', () => {
    const { container } = render(<MessageBubble message={msg({ message_type: 'sticker', content: 'Sticker: 999' })} />);
    expect(container.querySelector('img.w-20')).toHaveAttribute(
      'src',
      'https://stickershop.line-scdn.net/stickershop/v1/sticker/999/android/sticker.png'
    );
  });

  it('malformed JSON (not valid JSON, no legacy match) falls back to the "😊 Sticker" placeholder, not a crash', () => {
    render(<MessageBubble message={msg({ message_type: 'sticker', content: '{not valid json' })} />);
    expect(screen.getByText(/Sticker/)).toBeInTheDocument();
    expect(screen.getByText('😊 Sticker')).toBeInTheDocument();
  });
});

describe('MessageBubble — video', () => {
  it('an absolute http(s) URL wins even over an ID: match (video branch order differs from image/audio)', () => {
    const { container } = render(
      <MessageBubble message={msg({ message_type: 'video', content: 'https://cdn.example.com/v.mp4?ID:123' })} />
    );
    expect(container.querySelector('source')).toHaveAttribute('src', 'https://cdn.example.com/v.mp4?ID:123');
  });

  it('an ID: NNN (non-http) content proxies through line_content.php', () => {
    const { container } = render(<MessageBubble message={msg({ message_type: 'video', content: 'ID:456' })} />);
    expect(container.querySelector('source')).toHaveAttribute('src', 'api/line_content.php?id=456');
  });

  it('a bare non-http content proxies with the raw content as id', () => {
    const { container } = render(<MessageBubble message={msg({ message_type: 'video', content: 'raw-id' })} />);
    expect(container.querySelector('source')).toHaveAttribute('src', 'api/line_content.php?id=raw-id');
  });
});

describe('MessageBubble — audio', () => {
  it('an ID: NNN content resolves through the line_content.php proxy (ID-first priority, same as image)', () => {
    const { container } = render(<MessageBubble message={msg({ message_type: 'audio', content: 'ID:789' })} />);
    expect(container.querySelector('source')).toHaveAttribute('src', 'api/line_content.php?id=789');
  });

  it('an absolute http(s) URL passes through unchanged', () => {
    const { container } = render(<MessageBubble message={msg({ message_type: 'audio', content: 'https://cdn/a.mp3' })} />);
    expect(container.querySelector('source')).toHaveAttribute('src', 'https://cdn/a.mp3');
  });
});

describe('MessageBubble — location (CLAUDE.md gap #2, SSR-only)', () => {
  it('parses "[location] Address (lat, lng)" and renders a Google Maps link', () => {
    const { container } = render(
      <MessageBubble message={msg({ message_type: 'location', content: '[location] 123 ถนนสุขุมวิท (13.7563, 100.5018)' })} />
    );
    const link = container.querySelector('a.block') as HTMLAnchorElement;
    expect(link).toHaveAttribute('href', 'https://www.google.com/maps?q=13.7563,100.5018');
    expect(screen.getByText('123 ถนนสุขุมวิท')).toBeInTheDocument();
  });

  it('unparseable location content falls back to the generic 📍 Location placeholder', () => {
    render(<MessageBubble message={msg({ message_type: 'location', content: 'garbage' })} />);
    expect(screen.getByText('📍 Location')).toBeInTheDocument();
  });
});

describe('MessageBubble — file', () => {
  it('valid JSON {name,url} renders a download link', () => {
    render(<MessageBubble message={msg({ message_type: 'file', content: JSON.stringify({ name: 'ใบสั่งยา.pdf', url: 'https://x/f.pdf' }) })} />);
    const link = screen.getByRole('link', { name: /ใบสั่งยา\.pdf/ });
    expect(link).toHaveAttribute('href', 'https://x/f.pdf');
  });

  it('malformed JSON falls back to name="File", url="#" rather than crashing', () => {
    render(<MessageBubble message={msg({ message_type: 'file', content: '{not valid json' })} />);
    const link = screen.getByRole('link', { name: /File/ });
    expect(link).toHaveAttribute('href', '#');
  });
});

describe('MessageBubble — flex', () => {
  it('renders the structural flex tree for a valid bubble payload', () => {
    const { container } = render(
      <MessageBubble
        message={msg({
          message_type: 'flex',
          content: JSON.stringify({ type: 'bubble', body: { type: 'box', contents: [{ type: 'text', text: 'Flex body' }] } }),
        })}
      />
    );
    expect(container.querySelector('[data-flex="bubble"]')).toBeInTheDocument();
    expect(screen.getByText('Flex body')).toBeInTheDocument();
  });

  it('malformed JSON shows the "Flex Message (Error)" fallback, not a crash', () => {
    render(<MessageBubble message={msg({ message_type: 'flex', content: '{not valid json' })} />);
    expect(screen.getByText('Flex Message (Error)')).toBeInTheDocument();
  });
});

describe('MessageBubble — unrecognized/other type', () => {
  it('falls back to a capitalized-type placeholder', () => {
    render(<MessageBubble message={msg({ message_type: 'weird_type', content: 'x' })} />);
    expect(screen.getByText('Weird_type')).toBeInTheDocument();
  });

  it('a null message_type renders the empty-label fallback box without crashing', () => {
    const { container } = render(<MessageBubble message={msg({ message_type: null, content: 'x' })} />);
    expect(container.querySelector('.fa-file-alt')).toBeInTheDocument();
  });
});
