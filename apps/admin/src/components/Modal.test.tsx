import { fireEvent, render, screen } from '@testing-library/react';
import { Modal } from './Modal';

describe('Modal', () => {
  it('renders nothing when closed', () => {
    render(
      <Modal open={false} onClose={jest.fn()} title="จัดการ Tags">
        body
      </Modal>
    );
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });

  it('renders title, body, and footer when open', () => {
    render(
      <Modal open onClose={jest.fn()} title="จัดการ Tags" footer={<button type="button">ปิด</button>}>
        <p>เนื้อหา</p>
      </Modal>
    );
    expect(screen.getByRole('dialog', { name: 'จัดการ Tags' })).toBeInTheDocument();
    expect(screen.getByText('เนื้อหา')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'ปิด' })).toBeInTheDocument();
  });

  it('calls onClose when the close button is clicked', () => {
    const onClose = jest.fn();
    render(
      <Modal open onClose={onClose} title="จัดการ Tags">
        body
      </Modal>
    );
    fireEvent.click(screen.getByRole('button', { name: 'Close' }));
    expect(onClose).toHaveBeenCalledTimes(1);
  });
});
