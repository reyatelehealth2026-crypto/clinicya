import { render, screen } from '@testing-library/react';
import { FlexComponent, FlexMessage } from './index';

describe('FlexComponent (renderFlexComponent dispatch)', () => {
  it('dispatches text/image/button/separator/spacer/box by type', () => {
    const { container, rerender } = render(<FlexComponent comp={{ type: 'text', text: 'hi' }} />);
    expect(screen.getByText('hi')).toBeInTheDocument();

    rerender(<FlexComponent comp={{ type: 'image', url: 'https://x/a.png' }} />);
    expect(container.querySelector('img[data-flex-type="image"]')).toBeInTheDocument();

    rerender(<FlexComponent comp={{ type: 'button', action: { label: 'Go' } }} />);
    expect(screen.getByRole('button', { name: 'Go' })).toBeInTheDocument();

    rerender(<FlexComponent comp={{ type: 'separator' }} />);
    expect(container.querySelector('[data-flex-type="separator"]')).toBeInTheDocument();

    rerender(<FlexComponent comp={{ type: 'spacer' }} />);
    expect(container.querySelector('[data-flex-type="spacer"]')).toBeInTheDocument();

    rerender(<FlexComponent comp={{ type: 'box', contents: [{ type: 'text', text: 'nested' }] }} />);
    expect(screen.getByText('nested')).toBeInTheDocument();
  });

  it('renders a flex:1 filler div for type "filler" (no dedicated component file, dispatched inline)', () => {
    const { container } = render(<FlexComponent comp={{ type: 'filler' }} />);
    const el = container.querySelector('[data-flex-type="filler"]');
    expect(el).toBeInTheDocument();
    expect(el).toHaveStyle({ flex: 1 });
  });

  it('renders nothing for an unrecognized type or a missing comp (matches the JS default: branch returning "")', () => {
    const { container: c1 } = render(<FlexComponent comp={{ type: 'unknown-type' }} />);
    expect(c1.firstChild).toBeNull();
    const { container: c2 } = render(<FlexComponent comp={null} />);
    expect(c2.firstChild).toBeNull();
  });
});

describe('FlexMessage (renderFlexMessage + SSR container script fallback cascade)', () => {
  it('malformed JSON -> "Flex Message (Error)" fallback', () => {
    render(<FlexMessage content="{not valid json" />);
    expect(screen.getByText('Flex Message (Error)')).toBeInTheDocument();
  });

  it('valid JSON but not bubble/carousel -> plain "Flex Message" fallback (no "(Error)")', () => {
    render(<FlexMessage content={JSON.stringify({ type: 'something-else' })} />);
    expect(screen.getByText('Flex Message')).toBeInTheDocument();
    expect(screen.queryByText('Flex Message (Error)')).not.toBeInTheDocument();
  });

  it('a bare bubble payload renders via FlexBubble', () => {
    const { container } = render(
      <FlexMessage content={JSON.stringify({ type: 'bubble', body: { type: 'box', contents: [{ type: 'text', text: 'X' }] } })} />
    );
    expect(container.querySelector('[data-flex="bubble"]')).toBeInTheDocument();
  });

  it('unwraps the outer LINE {type:"flex", contents:{...}} envelope before routing', () => {
    const { container } = render(
      <FlexMessage
        content={JSON.stringify({
          type: 'flex',
          contents: { type: 'bubble', body: { type: 'box', contents: [{ type: 'text', text: 'Wrapped' }] } },
        })}
      />
    );
    expect(container.querySelector('[data-flex="bubble"]')).toBeInTheDocument();
    expect(screen.getByText('Wrapped')).toBeInTheDocument();
  });

  it('a 2-bubble carousel with button/separator/spacer/image/text renders one element per LINE flex component in header/hero/body/footer order (structural, not byte/pixel)', () => {
    const payload = {
      type: 'carousel',
      contents: [
        {
          type: 'bubble',
          header: { type: 'box', contents: [{ type: 'text', text: 'Header 1' }] },
          hero: { type: 'image', url: 'https://x/hero1.png' },
          body: {
            type: 'box',
            contents: [
              { type: 'text', text: 'Body 1' },
              { type: 'separator' },
              { type: 'spacer' },
            ],
          },
          footer: { type: 'box', contents: [{ type: 'button', action: { label: 'Buy 1' } }] },
        },
        {
          type: 'bubble',
          header: { type: 'box', contents: [{ type: 'text', text: 'Header 2' }] },
          hero: { type: 'image', url: 'https://x/hero2.png' },
          body: {
            type: 'box',
            contents: [
              { type: 'text', text: 'Body 2' },
              { type: 'separator' },
              { type: 'spacer' },
            ],
          },
          footer: { type: 'box', contents: [{ type: 'button', action: { label: 'Buy 2' } }] },
        },
      ],
    };

    const { container } = render(<FlexMessage content={JSON.stringify(payload)} />);

    expect(container.querySelector('[data-flex="carousel"]')).toBeInTheDocument();
    const bubbles = container.querySelectorAll('[data-flex="bubble"]');
    expect(bubbles).toHaveLength(2);

    bubbles.forEach((bubble, i) => {
      const n = i + 1;
      const slots = Array.from(bubble.children).map((el) => el.getAttribute('data-flex-slot'));
      expect(slots).toEqual(['header', 'hero', 'body', 'footer']);

      expect(bubble.querySelector('[data-flex-slot="header"]')).toHaveTextContent(`Header ${n}`);
      expect(bubble.querySelector('[data-flex-slot="hero"] img')).toHaveAttribute('src', `https://x/hero${n}.png`);
      const body = bubble.querySelector('[data-flex-slot="body"]') as HTMLElement;
      expect(body).toHaveTextContent(`Body ${n}`);
      expect(body.querySelector('[data-flex-type="separator"]')).toBeInTheDocument();
      expect(body.querySelector('[data-flex-type="spacer"]')).toBeInTheDocument();
      expect(bubble.querySelector('[data-flex-slot="footer"]')).toHaveTextContent(`Buy ${n}`);
    });
  });
});
