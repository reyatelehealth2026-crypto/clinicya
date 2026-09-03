import { fireEvent, render, screen, within } from '@testing-library/react';

jest.mock('next/navigation', () => ({
  redirect: jest.fn((url: string) => {
    throw new Error(`REDIRECT:${url}`);
  }),
}));
jest.mock('@reya/line', () => ({
  multicastMessage: jest.fn(),
  broadcastMessage: jest.fn(),
}));

import { SendComposeForm } from './SendComposeForm';

const BASE_PROPS = {
  templates: [{ id: 1, name: 'Template A', category: 'FAQ', messageType: 'text', content: 'Hello there' }],
  groups: [{ id: 1, name: 'VIP', memberCount: 10 }],
  segments: [{ id: 1, name: 'High value', userCount: 40 }],
  tags: [
    { id: 1, name: 'สนใจโปร', userCount: 5 },
    { id: 2, name: 'ลูกค้าเก่า', userCount: 8 },
  ],
  totalUsers: 1234,
};

describe('SendComposeForm — target-type panels', () => {
  it('defaults to the database target panel showing totalUsers', () => {
    render(<SendComposeForm {...BASE_PROPS} />);
    expect(screen.getByText(/จะส่งข้อความถึงผู้ใช้ในฐานข้อมูล/)).toBeInTheDocument();
    expect(screen.getAllByText('1,234').length).toBeGreaterThan(0);
  });

  it('switching to target_type=tag reveals the tag checklist', () => {
    render(<SendComposeForm {...BASE_PROPS} />);
    fireEvent.click(screen.getByRole('radio', { name: /Tag/ }));
    expect(screen.getByText('สนใจโปร')).toBeInTheDocument();
    expect(screen.getByText('ลูกค้าเก่า')).toBeInTheDocument();
    expect(screen.getByText('ยังไม่ได้เลือก Tag')).toBeInTheDocument();
  });

  it('"เลือกทั้งหมด" selects every tag; the counter updates', () => {
    render(<SendComposeForm {...BASE_PROPS} />);
    fireEvent.click(screen.getByRole('radio', { name: /Tag/ }));
    fireEvent.click(screen.getByText('เลือกทั้งหมด'));
    expect(screen.getByText('เลือกแล้ว 2 Tag')).toBeInTheDocument();
  });

  it('switching to target_type=segment reveals the segment select', () => {
    render(<SendComposeForm {...BASE_PROPS} />);
    fireEvent.click(screen.getByRole('radio', { name: 'Segment' }));
    expect(screen.getByLabelText('เลือก Customer Segment')).toBeInTheDocument();
  });

  it('switching to target_type=group reveals the group select with member counts', () => {
    render(<SendComposeForm {...BASE_PROPS} />);
    fireEvent.click(screen.getByRole('radio', { name: 'กลุ่ม' }));
    expect(screen.getByText('VIP (10 คน)')).toBeInTheDocument();
  });
});

describe('SendComposeForm — message type switching + template quick-load', () => {
  it('flex message type reveals the Flex JSON textarea', () => {
    render(<SendComposeForm {...BASE_PROPS} />);
    fireEvent.click(screen.getByRole('radio', { name: /Flex Message/ }));
    expect(screen.getByLabelText('Flex Message JSON')).toBeInTheDocument();
  });

  it('clicking a template button loads its content into the text textarea and switches message_type', () => {
    render(<SendComposeForm {...BASE_PROPS} />);
    fireEvent.click(screen.getByRole('radio', { name: /Flex Message/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Template A' }));
    // message_type flips back to 'text' (the template's own type) -> text textarea reappears with loaded content.
    expect(screen.getByRole('textbox', { name: 'ข้อความ' })).toHaveValue('Hello there');
  });
});

describe('SendComposeForm — WS-2 type-to-confirm modal (send.php lines 474-530/792-805)', () => {
  function fillRequiredFields() {
    fireEvent.change(screen.getByLabelText('หัวข้อ (สำหรับบันทึก)'), { target: { value: 'โปรทดสอบ' } });
  }

  it('pre-flight: blocks opening the modal with a Thai error when title is empty', () => {
    render(<SendComposeForm {...BASE_PROPS} />);
    fireEvent.click(screen.getByRole('button', { name: /ส่ง Broadcast/ }));
    expect(screen.getByRole('alert')).toHaveTextContent('กรุณากรอกหัวข้อ Broadcast');
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });

  it('pre-flight: blocks opening the modal when target_type=tag and no tag is selected', () => {
    render(<SendComposeForm {...BASE_PROPS} />);
    fillRequiredFields();
    fireEvent.click(screen.getByRole('radio', { name: /Tag/ }));
    fireEvent.click(screen.getByRole('button', { name: /ส่ง Broadcast/ }));
    expect(screen.getByRole('alert')).toHaveTextContent('กรุณาเลือก Tag อย่างน้อย 1 รายการ');
  });

  it('pre-flight: blocks opening the modal when send_mode=schedule and no datetime chosen', () => {
    render(<SendComposeForm {...BASE_PROPS} />);
    fillRequiredFields();
    fireEvent.click(screen.getByRole('radio', { name: 'ตั้งเวลาส่ง' }));
    fireEvent.click(screen.getByRole('button', { name: /ตั้งเวลาส่ง Broadcast/ }));
    expect(screen.getByRole('alert')).toHaveTextContent('กรุณาเลือกวันและเวลาที่ต้องการส่ง');
  });

  it('opens the modal with the target label + frozen totalUsers count once pre-flight passes', () => {
    render(<SendComposeForm {...BASE_PROPS} />);
    fillRequiredFields();
    fireEvent.click(screen.getByRole('button', { name: /ส่ง Broadcast/ }));
    const dialog = screen.getByRole('dialog');
    expect(dialog).toBeInTheDocument();
    expect(screen.getByText('ผู้ใช้ในฐานข้อมูลทั้งหมด')).toBeInTheDocument();
    expect(within(dialog).getByText('1,234')).toBeInTheDocument();
  });

  it('shows the cost estimate box only when totalUsers > 500', () => {
    const { rerender } = render(<SendComposeForm {...BASE_PROPS} totalUsers={500} />);
    fillRequiredFields();
    fireEvent.click(screen.getByRole('button', { name: /ส่ง Broadcast/ }));
    expect(screen.queryByText(/ต้นทุน LINE Push/)).not.toBeInTheDocument();

    rerender(<SendComposeForm {...BASE_PROPS} totalUsers={501} />);
    fillRequiredFields();
    fireEvent.click(screen.getByRole('button', { name: /ส่ง Broadcast/ }));
    expect(screen.getByText(/ต้นทุน LINE Push/)).toBeInTheDocument();
    expect(screen.getByText('ประมาณ ฿150')).toBeInTheDocument(); // round(501 * 0.30)
  });

  it('the confirm button stays disabled until the input is EXACTLY "SEND" (case-sensitive)', () => {
    render(<SendComposeForm {...BASE_PROPS} />);
    fillRequiredFields();
    fireEvent.click(screen.getByRole('button', { name: /ส่ง Broadcast/ }));

    const confirmBtn = screen.getByRole('button', { name: 'ยืนยันส่ง' });
    const input = screen.getByPlaceholderText('SEND');
    expect(confirmBtn).toBeDisabled();

    fireEvent.change(input, { target: { value: 'send' } }); // lowercase — must NOT enable
    expect(confirmBtn).toBeDisabled();

    fireEvent.change(input, { target: { value: 'SEND ' } }); // trailing space — must NOT enable
    expect(confirmBtn).toBeDisabled();

    fireEvent.change(input, { target: { value: 'SEND' } });
    expect(confirmBtn).toBeEnabled();
  });

  it('"ยกเลิก" closes the modal without submitting', () => {
    render(<SendComposeForm {...BASE_PROPS} />);
    fillRequiredFields();
    fireEvent.click(screen.getByRole('button', { name: /ส่ง Broadcast/ }));
    fireEvent.click(screen.getByRole('button', { name: 'ยกเลิก' }));
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
  });
});
