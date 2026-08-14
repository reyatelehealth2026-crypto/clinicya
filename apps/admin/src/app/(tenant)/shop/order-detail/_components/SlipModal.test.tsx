import { render, screen, fireEvent } from '@testing-library/react';
import { SlipModal } from './SlipModal';

describe('SlipModal', () => {
  it('renders nothing when src is null', () => {
    const { container } = render(<SlipModal src={null} onClose={() => {}} />);
    expect(container).toBeEmptyDOMElement();
  });

  it('renders the image when src is set', () => {
    render(<SlipModal src="/uploads/slips/a.jpg" onClose={() => {}} />);
    expect(screen.getByAltText('slip')).toHaveAttribute('src', '/uploads/slips/a.jpg');
  });

  it('calls onClose when the close button is clicked', () => {
    const onClose = jest.fn();
    render(<SlipModal src="/uploads/slips/a.jpg" onClose={onClose} />);
    fireEvent.click(screen.getByRole('button', { name: 'Close' }));
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('calls onClose when clicking the backdrop (not the image)', () => {
    const onClose = jest.fn();
    render(<SlipModal src="/uploads/slips/a.jpg" onClose={onClose} />);
    fireEvent.click(screen.getByRole('dialog'));
    expect(onClose).toHaveBeenCalledTimes(1);
  });

  it('does NOT call onClose when clicking the image itself', () => {
    const onClose = jest.fn();
    render(<SlipModal src="/uploads/slips/a.jpg" onClose={onClose} />);
    fireEvent.click(screen.getByAltText('slip'));
    expect(onClose).not.toHaveBeenCalled();
  });

  it('calls onClose on Escape keydown', () => {
    const onClose = jest.fn();
    render(<SlipModal src="/uploads/slips/a.jpg" onClose={onClose} />);
    fireEvent.keyDown(document, { key: 'Escape' });
    expect(onClose).toHaveBeenCalledTimes(1);
  });
});
