import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { fetchCrmData } from './_lib/crmData';
import { formatNumber } from './_lib/format';
import { KpiCard } from './_components/KpiCard';
import { SectionCard, SectionActionLink, EmptyState } from './_components/SectionCard';

/**
 * crm.tsx — Server Component port of includes/dashboard/crm.php. Mirrors
 * the PHP source's section order: KPI row (4 tiles) -> 3-panel grid
 * (Tags / Auto Tag Rules / Recent Customers) -> quick-actions link rail (8
 * static links to still-PHP pages, per the brief rendered as plain anchors).
 */
export interface CrmTabProps {
  db: Kysely<TenantDB>;
  currentBotId: number | null;
}

interface QuickAction {
  href: string;
  label: string;
}

/** Verbatim port of crm.php's `$actions` array (lines 207-216) — hrefs/labels, in the same order. */
const QUICK_ACTIONS: QuickAction[] = [
  { href: 'users.php', label: 'ดูลูกค้าทั้งหมด' },
  { href: 'user-tags.php', label: 'จัดการ Tags' },
  { href: 'auto-tag-rules.php', label: 'Auto Tag Rules' },
  { href: 'customer-segments.php', label: 'Segments' },
  { href: 'drip-campaigns.php', label: 'Drip Campaigns' },
  { href: 'broadcast.php', label: 'Broadcast' },
  { href: 'analytics.php?tab=crm', label: 'Analytics' },
  { href: 'link-tracking.php', label: 'Link Tracking' },
];

export async function CrmTab({ db, currentBotId }: CrmTabProps) {
  const data = await fetchCrmData(db, currentBotId);

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 24 }} data-testid="crm-tab">
      {/* CRM KPI row */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 16 }}>
        <KpiCard
          accent="indigo"
          label="ลูกค้าทั้งหมด"
          value={formatNumber(data.stats.totalCustomers)}
          footer={`+${formatNumber(data.stats.new7Days)} ใน 7 วัน`}
          testId="kpi-total-customers"
        />
        <KpiCard accent="emerald" label="ใหม่วันนี้" value={formatNumber(data.stats.newToday)} footer="ลูกค้า" testId="kpi-new-today" />
        <KpiCard accent="violet" label="Tags" value={formatNumber(data.stats.totalTags)} footer="กลุ่มลูกค้า" testId="kpi-total-tags" />
        <KpiCard accent="amber" label="Auto Rules" value={formatNumber(data.stats.autoRules)} footer="กฎอัตโนมัติ" testId="kpi-auto-rules" />
      </div>

      {/* 3-panel grid: Tags / Auto Tag Rules / Recent Customers */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(280px, 1fr))', gap: 24 }}>
        <SectionCard title="Tags" accent="violet" flush action={<SectionActionLink href="user-tags.php" label="จัดการ" />} testId="tags-section">
          {data.tags.length === 0 ? (
            <EmptyState title="ยังไม่มี Tags" subtitle="สร้าง tag เพื่อแบ่งกลุ่มลูกค้าและส่งแคมเปญได้ตรงกลุ่ม" ctaHref="user-tags.php" ctaLabel="สร้าง Tag แรก" testId="tags-empty" />
          ) : (
            <div style={{ maxHeight: 340, overflowY: 'auto' }} data-testid="tags-list">
              {data.tags.map((tag) => (
                <div key={tag.id} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '12px 20px', borderBottom: '1px solid #f1f5f9' }}>
                  <span style={{ width: 8, height: 8, borderRadius: 999, flexShrink: 0, background: tag.color ?? '#3B82F6' }} />
                  <div style={{ flex: 1, minWidth: 0, display: 'flex', alignItems: 'center', gap: 8 }}>
                    <span style={{ fontSize: 13, fontWeight: 600, color: '#132235' }}>{tag.name}</span>
                    {tag.tagType === 'auto' ? (
                      <span style={{ padding: '2px 7px', borderRadius: 6, fontSize: 10, fontWeight: 700, background: '#fff7ed', color: '#ea580c', border: '1px solid #fed7aa' }}>Auto</span>
                    ) : null}
                    {tag.tagType === 'system' ? (
                      <span style={{ padding: '2px 7px', borderRadius: 6, fontSize: 10, fontWeight: 700, background: '#eff6ff', color: '#2563eb', border: '1px solid #bfdbfe' }}>System</span>
                    ) : null}
                  </div>
                  <span style={{ fontSize: 14, fontWeight: 700, color: '#5f7286' }}>{formatNumber(tag.customerCount)}</span>
                </div>
              ))}
            </div>
          )}
        </SectionCard>

        <SectionCard title="Auto Tag Rules" accent="amber" flush action={<SectionActionLink href="auto-tag-rules.php" label="จัดการ" />} testId="auto-rules-section">
          {data.autoRules.length === 0 ? (
            <EmptyState title="ยังไม่มี Auto Rules" subtitle="ตั้งกฎติด tag อัตโนมัติเพื่อแบ่งกลุ่มลูกค้าโดยไม่ต้องทำมือ" ctaHref="auto-tag-rules.php" ctaLabel="สร้างกฎแรก" testId="auto-rules-empty" />
          ) : (
            <div style={{ maxHeight: 340, overflowY: 'auto' }} data-testid="auto-rules-list">
              {data.autoRules.map((rule) => (
                <div key={rule.id} style={{ display: 'flex', flexDirection: 'column', gap: 8, padding: '14px 20px', borderBottom: '1px solid #f1f5f9' }}>
                  <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                    <span style={{ fontSize: 13, fontWeight: 600, color: '#132235' }}>{rule.ruleName}</span>
                    <span
                      style={{
                        padding: '3px 10px',
                        borderRadius: 999,
                        fontSize: 10,
                        fontWeight: 700,
                        background: rule.isActive ? '#d1fae5' : '#f1f5f9',
                        color: rule.isActive ? '#059669' : '#94a3b8',
                        border: `1px solid ${rule.isActive ? '#a7f3d0' : '#e2e8f0'}`,
                      }}
                    >
                      {rule.isActive ? 'Active' : 'Inactive'}
                    </span>
                  </div>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 11, color: '#74869a' }}>
                    <span style={{ padding: '2px 8px', borderRadius: 6, background: '#eff6ff', color: '#2563eb', fontWeight: 600, border: '1px solid #bfdbfe' }}>{rule.triggerType}</span>
                    <span>→</span>
                    <span style={{ padding: '2px 8px', borderRadius: 6, fontWeight: 600, background: `${rule.tagColor ?? '#3B82F6'}14`, color: rule.tagColor ?? '#3B82F6' }}>{rule.tagName}</span>
                  </div>
                </div>
              ))}
            </div>
          )}
        </SectionCard>

        <SectionCard title="ลูกค้าล่าสุด" accent="indigo" flush action={<SectionActionLink href="users.php" label="ดูทั้งหมด" />} testId="recent-customers-section">
          {data.recentCustomers.length === 0 ? (
            <EmptyState title="ยังไม่มีลูกค้า" subtitle="ลูกค้าที่ลงทะเบียนผ่าน LINE จะแสดงที่นี่" testId="recent-customers-empty" />
          ) : (
            <div style={{ maxHeight: 340, overflowY: 'auto' }} data-testid="recent-customers-list">
              {data.recentCustomers.map((customer) => (
                <div key={customer.id} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '12px 20px', borderBottom: '1px solid #f1f5f9' }}>
                  <img
                    src={customer.pictureUrl || '/assets/img/avatar-default.svg'}
                    alt={customer.displayName ?? 'ลูกค้า'}
                    style={{ width: 36, height: 36, borderRadius: 10, objectFit: 'cover', flexShrink: 0 }}
                  />
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ fontSize: 13, fontWeight: 600, color: '#132235', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                      {customer.displayName ?? 'Unknown'}
                    </div>
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: 4, marginTop: 4 }}>
                      {customer.tags ? (
                        customer.tags.split(', ').map((tagName) => (
                          <span key={tagName} style={{ padding: '2px 8px', borderRadius: 6, fontSize: 10, fontWeight: 600, background: '#eef2ff', color: '#4338ca', border: '1px solid #c7d2fe' }}>
                            {tagName}
                          </span>
                        ))
                      ) : (
                        <span style={{ fontSize: 11, color: '#cbd5e1' }}>ไม่มี tag</span>
                      )}
                    </div>
                  </div>
                  <a href={`user-detail.php?id=${customer.id}`} aria-label="ดูรายละเอียด" style={{ color: '#6366f1', fontSize: 13 }}>
                    &rsaquo;
                  </a>
                </div>
              ))}
            </div>
          )}
        </SectionCard>
      </div>

      {/* Quick actions rail */}
      <SectionCard title="Quick Actions" accent="amber" testId="quick-actions-section">
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 10 }} data-testid="quick-actions-list">
          {QUICK_ACTIONS.map((action) => (
            <a
              key={action.href}
              href={action.href}
              style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: 10,
                padding: '10px 18px',
                borderRadius: 12,
                background: '#eef2ff',
                border: '1px solid #c7d2fe',
                textDecoration: 'none',
                fontSize: 13,
                fontWeight: 600,
                color: '#4f46e5',
              }}
            >
              {action.label}
            </a>
          ))}
        </div>
      </SectionCard>
    </div>
  );
}
