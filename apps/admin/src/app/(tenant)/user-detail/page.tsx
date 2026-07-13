import { redirect } from 'next/navigation';
import { PageHeader } from '@/components/PageHeader';
import { TagChip } from '@/components/Badge';
import { EmptyState } from '@/components/EmptyState';
import { requireTenantPageContext } from '../users/_lib/session';
import { isOdooIntegrationEnabled } from '../users/_lib/odoo';
import { formatDateDMY, formatDateISO, formatDateTimeDMY, formatMoney, formatNumber } from '../users/_lib/format';
import { getUserDetailPageData } from './queries';
import { updateUserInfoAction, addPointsAction } from './actions';

/**
 * /user-detail?id=N — Server Component port of user-detail.php. QUERY-PARAM
 * route on purpose (not `/user-detail/[id]`): crm.php and other still-PHP
 * admin pages link `user-detail.php?id=N`, and nginx routes both stacks at
 * the same clean path during coexistence — a dynamic segment would break
 * every existing cross-link the moment this page goes live for a tenant.
 *
 * OUT OF SCOPE (Phase 8 follow-up, not silently dropped): the Odoo ERP card
 * (user-detail.php lines 1022-1541), gated in PHP by
 * `ODOO_INTEGRATION_ENABLED` ALONE (a narrower, single global flag — NOT
 * dashboard.php's `$isOdooMode`, which additionally requires
 * shop_settings.order_data_source==='odoo'). When the flag is off (the
 * common/default case) the card is ABSENT ENTIRELY from the render tree —
 * not fetched, not stubbed, nothing — exactly like the PHP `<?php if
 * (...): ?>...<?php endif; ?>` block it replaces. When the flag is on, a
 * stub panel linking back to the still-live PHP page renders instead of the
 * real card (same boundary as /users' Odoo tab — see that page's module
 * doc).
 */

interface UserDetailPageProps {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}

function first(params: Record<string, string | string[] | undefined>, key: string): string | undefined {
  const v = params[key];
  return Array.isArray(v) ? v[0] : v;
}

export default async function UserDetailPage({ searchParams }: UserDetailPageProps) {
  const params = await searchParams;
  const userId = Number.parseInt(first(params, 'id') ?? '', 10) || 0;
  if (!userId) {
    redirect('/users');
  }

  const { db, session } = await requireTenantPageContext();
  const data = await getUserDetailPageData(db, userId, session.currentBotId);
  if (!data) {
    redirect('/users');
  }

  const { user, userTags, transactions, orderCount, totalSpent, messageCount, points, pointsHistory, tier, shopName, health } =
    data;

  const odooEnabled = isOdooIntegrationEnabled();
  const updated = first(params, 'updated') !== undefined;
  const pointsUpdated = first(params, 'points_updated') !== undefined;

  return (
    <div>
      <PageHeader
        title={user.displayName || 'รายละเอียดลูกค้า'}
        subtitle="รายละเอียดและข้อมูลลูกค้า"
        primaryAction={{ label: 'แชท', href: `/messages?user=${userId}`, variant: 'success' }}
        breadcrumb={[
          { label: 'Customers', href: '/users' },
          { label: user.displayName || 'รายละเอียด', href: null },
        ]}
      />

      {updated || pointsUpdated ? (
        <div role="status" className="ud-save-toast badge badge-success">
          บันทึกสำเร็จ!
        </div>
      ) : null}

      <div className="ud-grid">
        {/* Left column */}
        <div className="ud-col">
          {/* Member card */}
          <div className="ud-card">
            <div style={{ background: tier.color, color: '#fff', padding: 24, textAlign: 'center' }}>
              <p>{shopName}</p>
              <p>
                {tier.icon} MEMBER CARD
              </p>
            </div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 16, padding: 20 }}>
              <img src={user.pictureUrl || 'https://via.placeholder.com/80'} alt="" style={{ width: 80, height: 80, borderRadius: '50%' }} />
              <div>
                <h2>{user.displayName || 'Unknown'}</h2>
                <p style={{ color: tier.color }}>
                  {tier.icon} {tier.name}
                </p>
                {user.memberId ? <p>{user.memberId}</p> : <p>ID: {String(userId).padStart(6, '0')}</p>}
              </div>
            </div>
            <div style={{ padding: 20 }}>
              <div style={{ display: 'flex', gap: 12 }}>
                <div>
                  <p>{formatNumber(points.availablePoints)}</p>
                  <p>แต้มคงเหลือ</p>
                </div>
                <div>
                  <p>{formatNumber(points.totalPoints)}</p>
                  <p>สะสมทั้งหมด</p>
                </div>
                <div>
                  <p>{formatNumber(points.usedPoints)}</p>
                  <p>ใช้ไปแล้ว</p>
                </div>
              </div>
              <p>สมาชิกตั้งแต่: {formatDateDMY(user.createdAt)}</p>
            </div>
          </div>

          {/* Add/deduct points */}
          <div className="ud-card">
            <div className="ud-card-header">
              <h3 className="ud-card-title">จัดการแต้ม</h3>
            </div>
            <div className="ud-card-body">
              <form action={addPointsAction.bind(null, userId)}>
                <label className="ud-field">
                  จำนวนแต้ม (ติดลบ = หักแต้ม)
                  <input type="number" name="points" placeholder="เช่น 100 หรือ -50" className="ud-input" />
                </label>
                <label className="ud-field">
                  หมายเหตุ
                  <input type="text" name="description" placeholder="เหตุผล..." className="ud-input" />
                </label>
                <button type="submit" className="ud-btn-primary">
                  อัพเดทแต้ม
                </button>
              </form>
            </div>
          </div>

          {/* Points history */}
          {pointsHistory.length > 0 ? (
            <div className="ud-card">
              <div className="ud-card-header">
                <h3 className="ud-card-title">ประวัติแต้ม</h3>
              </div>
              <div className="ud-card-body">
                {pointsHistory.map((h) => (
                  <div key={h.id} className="ud-stat-row">
                    <div>
                      <div>{(h.description ?? '').slice(0, 25)}</div>
                      <div className="ud-info-label">{formatDateTimeDMY(h.createdAt)}</div>
                    </div>
                    <span className={`badge ${h.type === 'earn' ? 'badge-success' : 'badge-danger'}`}>
                      {h.type === 'earn' ? '+' : '-'}
                      {formatNumber(Math.abs(h.points))}
                    </span>
                  </div>
                ))}
              </div>
            </div>
          ) : null}

          {/* Stats summary */}
          <div className="ud-card">
            <div className="ud-card-header">
              <h3 className="ud-card-title">สรุปข้อมูล</h3>
            </div>
            <div className="ud-card-body">
              <div className="ud-stat-row">
                <span>จำนวนออเดอร์</span>
                <span>{formatNumber(orderCount)} รายการ</span>
              </div>
              <div className="ud-stat-row">
                <span>ยอดซื้อรวม</span>
                <span>฿{formatMoney(totalSpent)}</span>
              </div>
              <div className="ud-stat-row">
                <span>ข้อความทั้งหมด</span>
                <span>{formatNumber(messageCount)}</span>
              </div>
              <div className="ud-stat-row">
                <span>ระดับสมาชิก</span>
                <span style={{ color: tier.color }}>
                  {tier.icon} {tier.name}
                </span>
              </div>
            </div>
          </div>

          {/* Tags */}
          <div className="ud-card">
            <div className="ud-card-header">
              <h3 className="ud-card-title">Tags</h3>
            </div>
            <div className="ud-card-body" style={{ display: 'flex', flexWrap: 'wrap', gap: 8 }}>
              {userTags.length === 0 ? (
                <span>ยังไม่มี Tags</span>
              ) : (
                userTags.map((tag) => <TagChip key={tag.id} name={tag.name} color={tag.color} />)
              )}
            </div>
          </div>
        </div>

        {/* Right column */}
        <div className="ud-col">
          {/* Edit info form */}
          <div className="ud-card">
            <div className="ud-card-header">
              <h3 className="ud-card-title">ข้อมูลลูกค้า</h3>
            </div>
            <div className="ud-card-body">
              <form action={updateUserInfoAction.bind(null, userId)}>
                <label className="ud-field">
                  ชื่อที่แสดง (Display Name)
                  <input type="text" name="display_name" defaultValue={user.displayName ?? ''} className="ud-input" />
                </label>
                <label className="ud-field">
                  ชื่อจริง
                  <input type="text" name="real_name" defaultValue={user.realName ?? ''} className="ud-input" />
                </label>
                <label className="ud-field">
                  เลขสมาชิก (Member ID)
                  <input type="text" name="member_id" defaultValue={user.memberId ?? ''} className="ud-input" />
                </label>
                <label className="ud-field">
                  เบอร์โทร
                  <input type="tel" name="phone" defaultValue={user.phone ?? ''} className="ud-input" />
                </label>
                <label className="ud-field">
                  อีเมล
                  <input type="email" name="email" defaultValue={user.email ?? ''} className="ud-input" />
                </label>
                <label className="ud-field">
                  วันเกิด
                  <input type="date" name="birthday" defaultValue={formatDateISO(user.birthday)} className="ud-input" />
                </label>
                <label className="ud-field">
                  เพศ
                  <select name="gender" defaultValue={user.gender ?? ''} className="ud-select">
                    <option value="">-- เลือก --</option>
                    <option value="male">ชาย</option>
                    <option value="female">หญิง</option>
                    <option value="other">อื่นๆ</option>
                  </select>
                </label>
                <label className="ud-field">
                  ที่อยู่
                  <textarea name="address" defaultValue={user.address ?? ''} className="ud-textarea" />
                </label>
                <label className="ud-field">
                  จังหวัด
                  <input type="text" name="province" defaultValue={user.province ?? ''} className="ud-input" />
                </label>
                <label className="ud-field">
                  รหัสไปรษณีย์
                  <input type="text" name="postal_code" defaultValue={user.postalCode ?? ''} className="ud-input" />
                </label>
                <label className="ud-field">
                  หมายเหตุ
                  <textarea name="note" defaultValue={user.note ?? ''} className="ud-textarea" />
                </label>
                <button type="submit" className="ud-btn-primary">
                  บันทึกข้อมูล
                </button>
              </form>
            </div>
          </div>

          {/* Health info */}
          <div className="ud-card">
            <div className="ud-card-header">
              <h3 className="ud-card-title">ข้อมูลสุขภาพ (จาก LIFF)</h3>
              {health.hasLiffHealth ? <span>อัพเดทจาก LIFF</span> : null}
            </div>
            <div className="ud-card-body">
              {health.hasHealthInfo ? (
                <>
                  <div style={{ display: 'flex', gap: 16 }}>
                    <div className="ud-health-metric">
                      <p>{health.displayWeight ? health.displayWeight.toFixed(1) : '-'}</p>
                      <p>น้ำหนัก (กก.)</p>
                    </div>
                    <div className="ud-health-metric">
                      <p>{health.displayHeight ? health.displayHeight.toFixed(1) : '-'}</p>
                      <p>ส่วนสูง (ซม.)</p>
                    </div>
                    <div className="ud-health-metric">
                      <p>{health.bmi !== null ? health.bmi.toFixed(1) : '-'}</p>
                      <p>BMI</p>
                    </div>
                    <div className="ud-health-metric">
                      <p>{health.genderIcon}</p>
                      <p>{health.genderText}</p>
                    </div>
                    <div className="ud-health-metric">
                      <p>{health.displayBloodType || '-'}</p>
                      <p>กรุ๊ปเลือด</p>
                    </div>
                  </div>

                  {health.conditions.length > 0 ? (
                    <div>
                      <p>โรคประจำตัว</p>
                      {health.conditions.map((c) => (
                        <span key={c} className="ud-condition-chip">
                          {c}
                        </span>
                      ))}
                    </div>
                  ) : null}

                  {health.allergiesText ? (
                    <div>
                      <p>ยาที่แพ้</p>
                      <p>{health.allergiesText}</p>
                    </div>
                  ) : null}
                </>
              ) : (
                <EmptyState heading="ยังไม่มีข้อมูลสุขภาพ" sub="ลูกค้าสามารถกรอกข้อมูลสุขภาพผ่าน LIFF ได้" />
              )}
            </div>
          </div>

          {/* Orders history */}
          <div className="ud-card">
            <div className="ud-card-header">
              <h3 className="ud-card-title">ประวัติการสั่งซื้อ</h3>
              <a href={`/shop/orders?user=${userId}`}>ดูทั้งหมด →</a>
            </div>
            <div className="ud-card-body">
              {transactions.length === 0 ? (
                <EmptyState heading="ยังไม่มีประวัติการสั่งซื้อ" />
              ) : (
                transactions.map((order) => (
                  <a key={order.id} href={`/shop/order-detail?id=${order.id}`} className="ud-order-row">
                    <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                      <div>
                        <p>#{order.orderNumber || order.id}</p>
                        <p className="ud-info-label">{formatDateTimeDMY(order.createdAt)}</p>
                        {order.shippingName ? <p>{order.shippingName}</p> : null}
                      </div>
                      <div>
                        <p>฿{formatMoney(order.grandTotal)}</p>
                        <span className="ud-order-status">{order.status ?? 'pending'}</span>
                      </div>
                    </div>
                    {order.items.length > 0 ? (
                      <div>
                        {order.items.map((item, i) => (
                          <span key={`${order.id}-${i}`}>
                            {item.productName || 'สินค้า'} x{item.quantity}
                          </span>
                        ))}
                      </div>
                    ) : null}
                  </a>
                ))
              )}
            </div>
          </div>

          {odooEnabled ? (
            <div className="ud-card">
              <div className="ud-card-header">
                <h3 className="ud-card-title">Odoo ERP</h3>
              </div>
              <div className="ud-card-body">
                <EmptyState
                  heading="Odoo ERP card ยังอยู่บนระบบเดิม"
                  sub="ฟีเจอร์นี้จะถูกย้ายมาที่นี่ใน Phase 8 (Odoo stack) — ตอนนี้ใช้หน้า PHP เดิมต่อไปก่อน"
                  cta={{ label: 'เปิดหน้ารายละเอียดลูกค้า (PHP)', href: `/user-detail.php?id=${userId}` }}
                />
              </div>
            </div>
          ) : null}

          {/* LINE info */}
          <div className="ud-card">
            <div className="ud-card-header">
              <h3 className="ud-card-title">ข้อมูล LINE</h3>
            </div>
            <div className="ud-card-body">
              <div className="ud-info-row">
                <div className="ud-info-label">LINE User ID</div>
                <div>{user.lineUserId || '-'}</div>
              </div>
              <div className="ud-info-row">
                <div className="ud-info-label">Display Name</div>
                <div>{user.displayName || '-'}</div>
              </div>
              <div className="ud-info-row">
                <div className="ud-info-label">Status Message</div>
                <div>{user.statusMessage || '-'}</div>
              </div>
              <div className="ud-info-row">
                <div className="ud-info-label">เข้าร่วมเมื่อ</div>
                <div>{formatDateTimeDMY(user.createdAt)}</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
