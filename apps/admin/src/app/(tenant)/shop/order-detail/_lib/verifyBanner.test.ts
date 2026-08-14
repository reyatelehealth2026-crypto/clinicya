import { resolveVerifyBanner } from './verifyBanner';

describe('resolveVerifyBanner', () => {
  it('maps every known reason to its message + color', () => {
    expect(resolveVerifyBanner('ok')).toEqual({ message: expect.stringContaining('ตรวจสอบสำเร็จ'), color: 'emerald' });
    expect(resolveVerifyBanner('amount_mismatch').color).toBe('rose');
    expect(resolveVerifyBanner('account_mismatch').color).toBe('rose');
    expect(resolveVerifyBanner('not_a_slip').color).toBe('rose');
    expect(resolveVerifyBanner('duplicate_ref').color).toBe('amber');
    expect(resolveVerifyBanner('no_qr').color).toBe('amber');
    expect(resolveVerifyBanner('error').color).toBe('amber');
  });

  it('surfaces the GhostX detail for a scan_error:<detail> reason', () => {
    const b = resolveVerifyBanner('scan_error:ไม่มีรหัสอ้างอิงรายการ');
    expect(b.color).toBe('amber');
    expect(b.message).toContain('ไม่มีรหัสอ้างอิงรายการ');
  });

  it('falls back to a generic "เชื่อมต่อไม่ได้" when scan_error has no detail', () => {
    const b = resolveVerifyBanner('scan_error:');
    expect(b.message).toContain('เชื่อมต่อไม่ได้');
  });

  it('falls back to the generic slate banner for an unrecognized reason', () => {
    const b = resolveVerifyBanner('something_else');
    expect(b.color).toBe('slate');
    expect(b.message).toBe('ผลการตรวจสอบ: something_else');
  });
});
