import { render } from '@testing-library/react';
import { FlexSpacer } from './FlexSpacer';

describe('FlexSpacer', () => {
  it('defaults to the md size (3px)', () => {
    const { container } = render(<FlexSpacer spacer={{ type: 'spacer' }} />);
    expect(container.querySelector('[data-flex-type="spacer"]')).toHaveStyle({ height: '3px' });
  });

  it('maps every named size', () => {
    const { container, rerender } = render(<FlexSpacer spacer={{ type: 'spacer', size: 'xl' }} />);
    expect(container.querySelector('[data-flex-type="spacer"]')).toHaveStyle({ height: '6px' });
    rerender(<FlexSpacer spacer={{ type: 'spacer', size: 'xxl' }} />);
    expect(container.querySelector('[data-flex-type="spacer"]')).toHaveStyle({ height: '8px' });
  });
});
