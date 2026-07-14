import { render, screen } from '@testing-library/react';
import { FlexBox } from './FlexBox';

describe('FlexBox', () => {
  it('renders a vertical (column) layout by default', () => {
    const { container } = render(<FlexBox box={{ type: 'box', layout: 'vertical', contents: [] }} />);
    expect(container.querySelector('[data-flex-type="box"]')).toHaveStyle({ flexDirection: 'column' });
  });

  it('horizontal layout maps to flex-direction row', () => {
    const { container } = render(<FlexBox box={{ type: 'box', layout: 'horizontal', contents: [] }} />);
    expect(container.querySelector('[data-flex-type="box"]')).toHaveStyle({ flexDirection: 'row' });
  });

  it('dispatches each child through FlexComponent by type', () => {
    render(
      <FlexBox
        box={{
          type: 'box',
          contents: [
            { type: 'text', text: 'Hello' },
            { type: 'separator' },
            { type: 'spacer' },
          ],
        }}
      />
    );
    expect(screen.getByText('Hello')).toBeInTheDocument();
    expect(document.querySelector('[data-flex-type="separator"]')).toBeInTheDocument();
    expect(document.querySelector('[data-flex-type="spacer"]')).toBeInTheDocument();
  });

  it('tolerates a non-array contents field (malformed payload) without crashing', () => {
    // @ts-expect-error deliberately malformed input
    const { container } = render(<FlexBox box={{ type: 'box', contents: 'not-an-array' }} />);
    expect(container.querySelector('[data-flex-type="box"]')).toBeEmptyDOMElement();
  });

  it('carries the slot marker through as data-flex-slot', () => {
    const { container } = render(<FlexBox box={{ type: 'box', contents: [] }} slot="header" />);
    expect(container.querySelector('[data-flex-slot="header"]')).toBeInTheDocument();
  });
});
