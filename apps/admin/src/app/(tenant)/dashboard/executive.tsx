import type { Kysely } from 'kysely';
import type { TenantDB } from '@reya/db';
import { resolveExecutiveDateFilter, formatDateFilterDisplay } from './_lib/dateFilter';
import { computeTopIssues, responseTimeStyle, countAlertStyle } from './_lib/executiveLogic';
import { fetchExecutiveData } from './_lib/executiveData';
import { formatNumber, formatBaht } from './_lib/format';
import { KpiCard } from './_components/KpiCard';
import { SectionCard, EmptyState } from './_components/SectionCard';
import { HourlyActivityChart } from './_components/HourlyActivityChart';
import { DashboardCommandStrip } from './_components/DashboardCommandStrip';

/**
 * executive.tsx — Server Component port of includes/dashboard/executive.php.
 * Mirrors the PHP source's section order top to bottom: command strip ->
 * primary KPI row (5 tiles) -> attention zone (3 tiles) -> admin
 * performance + hourly activity -> problem messages + recent conversations
 * -> top issues tag cloud.
 *
 * NOT ported (out of scope, flagged in the build report): dashboard.php's
 * background "trigger scheduled broadcasts" `fastcgi_finish_request` +
 * `file_get_contents()` fire-and-forget hack (lines 43-54). That's a
 * cron/side-effect concern belonging to Phase 10 (Cron -> BullMQ), not a
 * dashboard *read* — and the fire-and-forget-curl-to-self pattern is
 * exactly the anti-pattern Phase 10 replaces with a real job queue, so
 * reproducing it here would be porting something the plan already schedules
 * for deletion.
 */
export interface ExecutiveTabProps {
  db: Kysely<TenantDB>;
  /** Raw `date` searchParam value, or undefined (defaults to today-in-Bangkok) — see resolveExecutiveDateFilter(). */
  dateParam: string | undefined;
}

export async function ExecutiveTab({ db, dateParam }: ExecutiveTabProps) {
  const { dateFilter, dateStart, dateEnd } = resolveExecutiveDateFilter(dateParam);
  const data = await fetchExecutiveData(db, dateStart, dateEnd);

  const response = responseTimeStyle(data.avgResponseTime);
  const unreadStyle = countAlertStyle(data.messageStats.unread);
  const problemCount = data.problemMessages.length;
  const problemStyle = countAlertStyle(problemCount);
  const topIssues = computeTopIssues(data.topIssueSourceMessages);
  const hasTopIssues = topIssues.some((issue) => issue.count > 0);

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 24 }} data-testid="executive-tab">
      <DashboardCommandStrip dateFilter={dateFilter} dateDisplay={formatDateFilterDisplay(dateFilter)} />

      {/* Primary KPI row */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: 16 }}>
        <KpiCard
          accent="indigo"
          label="ข้อความวันนี้"
          value={formatNumber(data.messageStats.total)}
          footer={`รับ ${formatNumber(data.messageStats.incoming)} / ส่ง ${formatNumber(data.messageStats.outgoing)}`}
          testId="kpi-messages-today"
        />
        <KpiCard
          accent="emerald"
          label="ลูกค้าติดต่อ"
          value={formatNumber(data.customersToday)}
          footer={`+${data.newCustomers} ใหม่`}
          testId="kpi-customers-contacted"
        />
        <KpiCard
          accent="amber"
          label="ออเดอร์"
          value={formatNumber(data.orderStats.total)}
          footer={`${data.orderStats.pending} รอดำเนินการ`}
          testId="kpi-orders"
        />
        <KpiCard
          accent="violet"
          label="รายได้"
          value={formatBaht(data.orderStats.revenue)}
          footer={`${data.orderStats.completed} สำเร็จ`}
          testId="kpi-revenue"
        />
        <KpiCard
          accent="indigo"
          label="วิดีโอคอล"
          value={formatNumber(data.videoStats.total)}
          footer={`เฉลี่ย ${(data.videoStats.avgDuration / 60).toFixed(1)} นาที`}
          testId="kpi-video-calls"
        />
      </div>

      {/* Attention zone */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 16 }}>
        <KpiCard accent={response.accent} label="เวลาตอบกลับเฉลี่ย" value={`${data.avgResponseTime} นาที`} footer={response.label} testId="kpi-avg-response-time" />
        <KpiCard accent={unreadStyle.accent} label="ยังไม่ได้อ่าน" value={formatNumber(data.messageStats.unread)} footer="ข้อความ" alert={unreadStyle.alert} testId="kpi-unread" />
        <KpiCard accent={problemStyle.accent} label="ปัญหา/ข้อร้องเรียน" value={String(problemCount)} footer="รายการ" alert={problemStyle.alert} testId="kpi-problem-count" />
      </div>

      {/* Admin performance + hourly activity */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 24 }}>
        <SectionCard title="ผลงาน Admin วันนี้" accent="indigo" flush testId="admin-performance-section">
          {data.adminPerformance.length === 0 ? (
            <EmptyState title="ไม่มีข้อมูลผลงาน Admin" testId="admin-performance-empty" />
          ) : (
            <div data-testid="admin-performance-list">
              {data.adminPerformance.map((admin, index) => (
                <div key={`${admin.adminName}-${index}`} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '12px 20px', borderBottom: '1px solid #f1f5f9' }}>
                  <div style={{ width: 32, height: 32, borderRadius: 8, background: '#4f46e5', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 800 }}>
                    {index + 1}
                  </div>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ fontSize: 14, fontWeight: 600, color: '#132235' }}>{admin.adminName || 'System/Bot'}</div>
                    <div style={{ fontSize: 11, color: '#74869a' }}>ดูแล {admin.customersHandled} ลูกค้า</div>
                  </div>
                  <div style={{ textAlign: 'right' }}>
                    <div style={{ fontSize: 18, fontWeight: 800, color: '#4f46e5' }}>{formatNumber(admin.messagesSent)}</div>
                    <div style={{ fontSize: 11, color: '#94a3b8' }}>ข้อความ</div>
                  </div>
                </div>
              ))}
            </div>
          )}
        </SectionCard>

        <SectionCard title="กิจกรรมรายชั่วโมง" accent="emerald" testId="hourly-activity-section">
          <HourlyActivityChart hourlyActivity={data.hourlyActivity} />
        </SectionCard>
      </div>

      {/* Problem messages + recent conversations */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 24 }}>
        <SectionCard
          title="ข้อความที่อาจเป็นปัญหา"
          accent="rose"
          flush
          alert={problemCount > 0}
          badge={
            <span style={{ padding: '4px 10px', borderRadius: 999, fontSize: 11, fontWeight: 700, background: '#fef2f2', color: '#dc2626', border: '1px solid #fecaca' }}>
              {problemCount} รายการ
            </span>
          }
          testId="problem-messages-section"
        >
          {data.problemMessages.length === 0 ? (
            <EmptyState title="ไม่พบข้อความที่เป็นปัญหา" testId="problem-messages-empty" />
          ) : (
            <div style={{ maxHeight: 400, overflowY: 'auto' }} data-testid="problem-messages-list">
              {data.problemMessages.map((msg) => (
                <a
                  key={msg.id}
                  href={`chat.php?user=${msg.userId ?? ''}`}
                  style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '12px 20px', borderBottom: '1px solid #f1f5f9', textDecoration: 'none', color: 'inherit' }}
                >
                  <img
                    src={msg.pictureUrl || '/assets/img/avatar-default.svg'}
                    alt={msg.displayName ?? 'ลูกค้า'}
                    style={{ width: 36, height: 36, borderRadius: 10, objectFit: 'cover', flexShrink: 0 }}
                  />
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                      <span style={{ fontSize: 13, fontWeight: 600, color: '#132235' }}>{msg.displayName || 'ลูกค้า'}</span>
                      <span style={{ fontSize: 11, color: '#94a3b8' }}>{msg.timeHm}</span>
                    </div>
                    <p style={{ fontSize: 12, color: '#5f7286', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', margin: 0 }}>{msg.content ?? ''}</p>
                  </div>
                </a>
              ))}
            </div>
          )}
        </SectionCard>

        <SectionCard title="การสนทนาล่าสุด" accent="indigo" flush testId="recent-conversations-section">
          {data.recentConversations.length === 0 ? (
            <EmptyState title="ยังไม่มีการสนทนาวันนี้" testId="recent-conversations-empty" />
          ) : (
            <div style={{ maxHeight: 400, overflowY: 'auto' }} data-testid="recent-conversations-list">
              {data.recentConversations.map((conv) => (
                <a
                  key={conv.id}
                  href={`chat.php?user=${conv.id}`}
                  style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '12px 20px', borderBottom: '1px solid #f1f5f9', textDecoration: 'none', color: 'inherit' }}
                >
                  <img
                    src={conv.pictureUrl || '/assets/img/avatar-default.svg'}
                    alt={conv.displayName ?? 'ลูกค้า'}
                    style={{ width: 36, height: 36, borderRadius: 10, objectFit: 'cover', flexShrink: 0 }}
                  />
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                      <span style={{ fontSize: 13, fontWeight: 600, color: '#132235' }}>{conv.displayName || 'ลูกค้า'}</span>
                      <span style={{ padding: '2px 8px', borderRadius: 6, fontSize: 10, fontWeight: 700, background: '#eef2ff', color: '#4338ca' }}>
                        {conv.messageCount} ข้อความ
                      </span>
                    </div>
                    <p style={{ fontSize: 12, color: '#5f7286', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', margin: 0 }}>{conv.lastMessage ?? ''}</p>
                  </div>
                  <span style={{ fontSize: 11, color: '#94a3b8', flexShrink: 0 }}>{conv.lastMessageHm}</span>
                </a>
              ))}
            </div>
          )}
        </SectionCard>
      </div>

      {/* Top issues */}
      <SectionCard title="หัวข้อที่ลูกค้าถามบ่อย" accent="amber" testId="top-issues-section">
        {!hasTopIssues ? (
          <EmptyState title="ยังไม่มีข้อมูลหัวข้อ" testId="top-issues-empty" />
        ) : (
          <div style={{ display: 'flex', flexWrap: 'wrap', gap: 10 }} data-testid="top-issues-list">
            {topIssues
              .filter((issue) => issue.count > 0)
              .map((issue) => (
                <div
                  key={issue.keyword}
                  style={{ display: 'inline-flex', alignItems: 'center', gap: 8, padding: '8px 16px', borderRadius: 999, background: '#fff7ed', border: '1px solid #fed7aa', fontSize: 13 }}
                >
                  <span style={{ fontWeight: 600, color: '#9a3412' }}>{issue.keyword}</span>
                  <span
                    style={{ display: 'inline-flex', alignItems: 'center', justifyContent: 'center', minWidth: 24, padding: '2px 8px', borderRadius: 999, background: '#fdba74', color: '#7c2d12', fontSize: 11, fontWeight: 700 }}
                  >
                    {issue.count}
                  </span>
                </div>
              ))}
          </div>
        )}
      </SectionCard>
    </div>
  );
}
