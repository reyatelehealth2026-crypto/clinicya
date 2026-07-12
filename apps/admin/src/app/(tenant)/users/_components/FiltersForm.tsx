import type { UserTagOption } from '../queries';
import type { UsersListFilters } from '../queries';

/**
 * FiltersForm — the 6 advanced-filter `<select>`s from users.php's
 * collapsible filter grid (lines 1126-1204: ระดับสมาชิก/tier,
 * แต้มสะสม/points, กิจกรรมล่าสุด/activity, ประวัติซื้อ/purchase, Tags/tag,
 * สถานะ/status). Server Component — plain selects inside the surrounding
 * `<form method="GET">` from Toolbar; submission is a normal GET navigation.
 */
export interface FiltersFormProps {
  filters: UsersListFilters;
  allTags: UserTagOption[];
}

export function FiltersForm({ filters, allTags }: FiltersFormProps) {
  return (
    <div className="filters-grid">
      <div>
        <label htmlFor="tier">ระดับสมาชิก</label>
        <select id="tier" name="tier" defaultValue={filters.tier}>
          <option value="">ทั้งหมด</option>
          <option value="bronze">🥉 Bronze</option>
          <option value="silver">🥈 Silver</option>
          <option value="gold">🥇 Gold</option>
          <option value="platinum">💎 Platinum</option>
        </select>
      </div>

      <div>
        <label htmlFor="points">แต้มสะสม</label>
        <select id="points" name="points" defaultValue={filters.points}>
          <option value="">ทั้งหมด</option>
          <option value="0-100">0-100 แต้ม</option>
          <option value="100-500">100-500 แต้ม</option>
          <option value="500-1000">500-1,000 แต้ม</option>
          <option value="1000+">1,000+ แต้ม</option>
        </select>
      </div>

      <div>
        <label htmlFor="activity">กิจกรรมล่าสุด</label>
        <select id="activity" name="activity" defaultValue={filters.activity}>
          <option value="">ทั้งหมด</option>
          <option value="today">วันนี้</option>
          <option value="7days">7 วันที่ผ่านมา</option>
          <option value="30days">30 วันที่ผ่านมา</option>
          <option value="inactive">ไม่มีกิจกรรม (&gt;30 วัน)</option>
        </select>
      </div>

      <div>
        <label htmlFor="purchase">ประวัติซื้อ</label>
        <select id="purchase" name="purchase" defaultValue={filters.purchase}>
          <option value="">ทั้งหมด</option>
          <option value="purchased">เคยซื้อแล้ว</option>
          <option value="never">ยังไม่เคยซื้อ</option>
          <option value="1000+">ซื้อ ≥ ฿1,000</option>
          <option value="5000+">ซื้อ ≥ ฿5,000</option>
        </select>
      </div>

      <div>
        <label htmlFor="tag">Tags</label>
        <select id="tag" name="tag" defaultValue={filters.tag ? String(filters.tag) : ''}>
          <option value="">ทั้งหมด</option>
          {allTags.map((tag) => (
            <option key={tag.id} value={tag.id}>
              {tag.name}
            </option>
          ))}
        </select>
      </div>

      <div>
        <label htmlFor="status">สถานะ</label>
        <select id="status" name="status" defaultValue={filters.status}>
          <option value="">ทั้งหมด</option>
          <option value="active">✅ Active</option>
          <option value="blocked">🚫 Blocked</option>
        </select>
      </div>

      <div>
        <button type="submit">กรองข้อมูล</button>
        <a href="/users">ล้างตัวกรอง</a>
      </div>
    </div>
  );
}
