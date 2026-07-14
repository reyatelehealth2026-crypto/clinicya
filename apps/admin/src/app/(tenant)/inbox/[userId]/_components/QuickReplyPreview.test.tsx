import { render, screen } from '@testing-library/react';
import { QuickReplyPreview } from './QuickReplyPreview';

describe('QuickReplyPreview', () => {
  it('renders nothing for an empty item list', () => {
    const { container } = render(<QuickReplyPreview items={[]} />);
    expect(container.firstChild).toBeNull();
  });

  it('renders an icon + label per item, icon selected by action.type', () => {
    render(
      <QuickReplyPreview
        items={[
          { action: { type: 'message', label: 'สวัสดี' } },
          { action: { type: 'uri', label: 'เว็บไซต์' } },
          { action: { type: 'postback', label: 'ยืนยัน' } },
          { action: { type: 'datetimepicker', label: 'นัดหมาย' } },
          { action: { type: 'camera', label: 'ถ่ายรูป' } },
          { action: { type: 'cameraRoll', label: 'อัลบั้ม' } },
          { action: { type: 'location', label: 'ตำแหน่ง' } },
        ]}
      />
    );
    expect(screen.getByText(/💬/)).toHaveTextContent('สวัสดี');
    expect(screen.getByText(/🔗/)).toHaveTextContent('เว็บไซต์');
    expect(screen.getByText(/📤/)).toHaveTextContent('ยืนยัน');
    expect(screen.getByText(/📅/)).toHaveTextContent('นัดหมาย');
    expect(screen.getByText(/📷/)).toHaveTextContent('ถ่ายรูป');
    expect(screen.getByText(/🖼️/)).toHaveTextContent('อัลบั้ม');
    expect(screen.getByText(/📍/)).toHaveTextContent('ตำแหน่ง');
  });

  it('defaults action.type to "message" and label to "" when absent', () => {
    render(<QuickReplyPreview items={[{}]} />);
    expect(screen.getByTitle('message')).toBeInTheDocument();
  });

  it('sets the title attribute to the raw action type', () => {
    render(<QuickReplyPreview items={[{ action: { type: 'uri', label: 'x' } }]} />);
    expect(screen.getByTitle('uri')).toBeInTheDocument();
  });
});
