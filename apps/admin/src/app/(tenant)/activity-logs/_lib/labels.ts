/**
 * labels.ts — ported verbatim from activity-logs.php's $logTypes/$actions
 * arrays (lines 38-63) and its per-type badge color classes (.log-badge.*
 * rules in the <style> block, lines 187-197).
 */
export const LOG_TYPE_LABELS: Record<string, string> = {
  auth: 'เข้าสู่ระบบ',
  user: 'ผู้ใช้',
  admin: 'แอดมิน',
  data: 'ข้อมูล',
  consent: 'ความยินยอม',
  message: 'ข้อความ',
  order: 'คำสั่งซื้อ',
  pharmacy: 'เภสัชกรรม',
  ai: 'AI',
  api: 'API',
  system: 'ระบบ',
};

export const ACTION_LABELS: Record<string, string> = {
  create: 'สร้าง',
  read: 'ดู',
  update: 'แก้ไข',
  delete: 'ลบ',
  login: 'เข้าสู่ระบบ',
  logout: 'ออกจากระบบ',
  export: 'ส่งออก',
  send: 'ส่ง',
  approve: 'อนุมัติ',
  reject: 'ปฏิเสธ',
};

/** Tailwind utility classes per log_type, translated 1:1 from the PHP source's `.log-badge.{type}` CSS rules. */
export const LOG_TYPE_BADGE_CLASSES: Record<string, string> = {
  auth: 'bg-blue-100 text-blue-700',
  user: 'bg-sky-100 text-sky-700',
  admin: 'bg-amber-100 text-amber-700',
  data: 'bg-slate-100 text-slate-600',
  consent: 'bg-emerald-100 text-emerald-700',
  message: 'bg-sky-100 text-sky-700',
  order: 'bg-emerald-100 text-emerald-700',
  pharmacy: 'bg-rose-100 text-rose-700',
  ai: 'bg-violet-100 text-violet-700',
  api: 'bg-slate-100 text-slate-700',
  system: 'bg-slate-100 text-slate-600',
};
const DEFAULT_BADGE_CLASSES = 'bg-slate-100 text-slate-600';

export function logTypeLabel(type: string): string {
  return LOG_TYPE_LABELS[type] ?? type;
}

export function actionLabel(action: string): string {
  return ACTION_LABELS[action] ?? action;
}

export function logTypeBadgeClasses(type: string): string {
  return LOG_TYPE_BADGE_CLASSES[type] ?? DEFAULT_BADGE_CLASSES;
}
