/**
 * verifyBanner.ts — port of the `?verify=` query-param result banner (PHP
 * lines 1029-1053): maps `verifySlipAction`'s redirect reason to a
 * message + color, including the `scan_error:<detail>` prefix branch and
 * the final generic fallback.
 */

export type VerifyBannerColor = 'emerald' | 'rose' | 'amber' | 'slate';

export interface VerifyBanner {
  message: string;
  color: VerifyBannerColor;
}

const VERIFY_MESSAGES: Record<string, VerifyBanner> = {
  ok: { message: '✅ ตรวจสอบสำเร็จ — GhostX ยืนยันสลิปและอนุมัติการชำระแล้ว', color: 'emerald' },
  amount_mismatch: { message: '❌ ยอดเงินในสลิปไม่ตรงกับยอดออเดอร์', color: 'rose' },
  account_mismatch: { message: '❌ บัญชีปลายทางในสลิปไม่ตรงกับบัญชีร้าน', color: 'rose' },
  not_a_slip: { message: '❌ QR ไม่ใช่สลิปโอนเงินที่ตรวจสอบได้', color: 'rose' },
  duplicate_ref: { message: '⚠️ สลิปนี้ถูกใช้กับออเดอร์อื่นแล้ว (กันสลิปซ้ำ)', color: 'amber' },
  no_qr: { message: '⚠️ สลิปนี้ไม่มีข้อมูล QR ให้ตรวจสอบ', color: 'amber' },
  error: { message: '⚠️ ตรวจสอบไม่สำเร็จ (เชื่อมต่อ GhostX ไม่ได้) ลองใหม่อีกครั้ง', color: 'amber' },
};

/** Port of the `$vMsg[$vk]` lookup + `scan_error:` prefix branch + generic fallback (PHP lines 1040-1048). */
export function resolveVerifyBanner(vk: string): VerifyBanner {
  const known = VERIFY_MESSAGES[vk];
  if (known) {
    return known;
  }
  if (vk.startsWith('scan_error')) {
    const detail = vk.slice('scan_error:'.length).trim();
    return { message: `⚠️ GhostX ตรวจสลิปไม่ผ่าน: ${detail !== '' ? detail : 'เชื่อมต่อไม่ได้'}`, color: 'amber' };
  }
  return { message: `ผลการตรวจสอบ: ${vk}`, color: 'slate' };
}
