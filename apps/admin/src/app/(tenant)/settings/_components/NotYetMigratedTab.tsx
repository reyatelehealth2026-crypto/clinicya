import { EmptyState } from '@/components/EmptyState';

/**
 * NotYetMigratedTab — shared placeholder for a settings.php tab that is
 * genuinely LIVE/reachable in real production (present in root
 * `/settings.php`'s uncommented `$tabs` whitelist — see settings/page.tsx's
 * module doc) but has not been ported to this Next shell yet. Rendered
 * instead of any silent fallback to another tab's content (e.g. `welcome`'s)
 * — per this batch's brief: "never a silent fallback to welcome's or any
 * other tab's content".
 *
 * Shared across settings batches — settingsConsentTax and later batches
 * (line/platform/general/notifications/quick-access, then telegram/liff/
 * vibe-selling) may IMPORT this component but must not edit this file.
 *
 * `quick-access` is included in the label map even though it is NOT one of
 * root settings.php's live (uncommented) `$tabs` entries — its PHP handler/
 * partial (`includes/settings/quick-access.php`) still exists and is
 * code-reachable if a future PHP change re-enables it, and this batch's
 * acceptance criteria explicitly tests this component's rendering for all
 * 5 "deferred tab keys" (line/platform/general/notifications/quick-access)
 * directly, independent of whether page.tsx's own `?tab=` routing can reach
 * it today.
 */
export type NotYetMigratedTabKey = 'line' | 'platform' | 'general' | 'notifications' | 'quick-access';

const TAB_LABELS: Record<NotYetMigratedTabKey, string> = {
  line: 'LINE Accounts',
  platform: 'การเชื่อมต่อแพลตฟอร์ม',
  general: 'ข้อมูลร้าน',
  notifications: 'การแจ้งเตือน',
  'quick-access': 'Quick Access',
};

export interface NotYetMigratedTabProps {
  tabKey: NotYetMigratedTabKey;
}

export function NotYetMigratedTab({ tabKey }: NotYetMigratedTabProps) {
  const label = TAB_LABELS[tabKey];

  return (
    <EmptyState
      icon={<span aria-hidden="true">🚧</span>}
      heading={`${label} — ยังไม่ได้ย้ายมาที่นี่ — ใช้หน้าเดิม`}
      sub="แท็บนี้ยังทำงานอยู่บนระบบเดิม กรุณาใช้หน้าเดิมต่อไปก่อนจนกว่าจะมีการย้ายมาที่นี่ในรอบถัดไป"
      cta={{ label: `ไปที่หน้าเดิม (${label})`, href: `/settings.php?tab=${tabKey}` }}
    />
  );
}
