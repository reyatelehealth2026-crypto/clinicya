import type { Kysely } from 'kysely';
import { sql } from 'kysely';
import type { TenantDB } from '@reya/db';
import { generateMemberId, getExistingUserColumns } from './columns';
import { calculateTier } from './tierService';
import { getUserPoints } from './loyaltyPoints';
import { floatOrNull, phpEmpty, strOrEmpty } from './phpCompat';

/**
 * handlers.ts — the four action handlers ported from api/member.php (812 lines, read in full):
 * `check` (GET, real write side effects), `get_card` (GET, pure read), `register` (POST),
 * `update_profile` (POST). Every branch mirrors api/member.php's control flow 1:1, including the
 * dynamic-column detection and the points_history/points_transactions table split flagged in
 * packages/contracts/src/member.ts's doc comment (contractNote §8) — do not "fix" either quirk here.
 */

export interface ActionResult {
  status: number;
  body: Record<string, unknown>;
}

/** Port of api/member.php's local `jsonResponse($success, $message, $data)` — always HTTP 200. */
function ok(success: boolean, message: string, data: Record<string, unknown> = {}): ActionResult {
  return { status: 200, body: { success, message, ...data } };
}

/**
 * packages/db's mysql2 pool has no `dateStrings: true`, so DATETIME/TIMESTAMP columns hydrate as JS
 * `Date` objects, not PHP PDO's raw `YYYY-MM-DD HH:MM:SS` strings — left unformatted this serializes to
 * a `Z`-suffixed ISO string via `JSON.stringify`, which is NOT what api/member.php's `get_card` actually
 * returns for `registered_at`. Same fix already applied in points-history's/health-profile's query.ts
 * (`formatPhpDate()`/`asDateTimeString()`) — mirrored here rather than imported, per this batch's
 * allowed-paths boundary (each miniapp route folder is self-contained).
 */
function asDateTimeString(value: string | Date | null | undefined): string | null {
  if (value === null || value === undefined) return null;
  if (typeof value === 'string') return value;
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())} ${pad(value.getHours())}:${pad(value.getMinutes())}:${pad(value.getSeconds())}`;
}

/** Same rationale as `asDateTimeString()`, for DATE columns (`users.birthday`) — `YYYY-MM-DD` only. */
function asDateString(value: string | Date | null | undefined): string | null {
  if (value === null || value === undefined) return null;
  if (typeof value === 'string') return value;
  const pad = (n: number) => String(n).padStart(2, '0');
  return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())}`;
}

type SqlValue = unknown;
interface InsertField {
  column: string;
  value: SqlValue;
}

async function insertRow(db: Kysely<TenantDB>, table: string, fields: InsertField[]): Promise<number> {
  const columnsSql = sql.join(fields.map((f) => sql.ref(f.column)));
  const valuesSql = sql.join(fields.map((f) => f.value));
  const result = await sql<never>`INSERT INTO ${sql.table(table)} (${columnsSql}) VALUES (${valuesSql})`.execute(db);
  return Number(result.insertId ?? 0);
}

// ---------------------------------------------------------------------------
// action=check
// ---------------------------------------------------------------------------

interface CheckUserRow {
  id: number;
  member_id: string | null;
  is_registered: number;
  first_name: string | null;
  last_name: string | null;
  points: number | string | null;
  display_name: string | null;
}

async function findUserForCheck(db: Kysely<TenantDB>, lineUserId: string, lineAccountId: number): Promise<CheckUserRow | null> {
  const scoped = await sql<CheckUserRow>`
    SELECT id, member_id, is_registered, first_name, last_name, points, display_name
    FROM users WHERE line_user_id = ${lineUserId} AND line_account_id = ${lineAccountId}
  `.execute(db);
  if (scoped.rows.length > 0) return scoped.rows[0]!;

  const unscoped = await sql<CheckUserRow>`
    SELECT id, member_id, is_registered, first_name, last_name, points, display_name
    FROM users WHERE line_user_id = ${lineUserId}
  `.execute(db);
  return unscoped.rows[0] ?? null;
}

/** Port of api/member.php::autoRegisterMember(). */
export async function autoRegisterMember(
  db: Kysely<TenantDB>,
  lineUserId: string,
  lineAccountId: number,
  displayName: string,
  pictureUrl: string
): Promise<CheckUserRow> {
  const memberId = await generateMemberId(db, lineAccountId);
  const existingColumns = await getExistingUserColumns(db);

  const fields: InsertField[] = [
    { column: 'line_account_id', value: lineAccountId },
    { column: 'line_user_id', value: lineUserId },
    { column: 'display_name', value: displayName || null },
    { column: 'picture_url', value: pictureUrl || null },
    { column: 'member_id', value: memberId },
    { column: 'is_registered', value: 1 },
    { column: 'registered_at', value: sql.raw('NOW()') },
    { column: 'created_at', value: sql.raw('NOW()') },
  ];
  if (existingColumns.has('member_tier')) fields.push({ column: 'member_tier', value: 'bronze' });
  if (existingColumns.has('points')) fields.push({ column: 'points', value: 50 });

  const userId = await insertRow(db, 'users', fields);

  try {
    await sql`
      INSERT INTO points_history (line_account_id, user_id, points, type, description, balance_after)
      VALUES (${lineAccountId}, ${userId}, 50, 'bonus', 'โบนัสต้อนรับสมาชิกใหม่ (Auto-Register)', 50)
    `.execute(db);
  } catch {
    // points_history table might not exist on this tenant DB — best-effort, matches PHP's catch.
  }

  return {
    id: userId,
    member_id: memberId,
    is_registered: 1,
    first_name: null,
    last_name: null,
    // Verbatim `$displayName` in PHP's return array (NOT `?: null`) — note this differs from the DB
    // INSERT above, which DOES use `displayName || null`. A brand-new user with no LINE display name
    // therefore gets `display_name: ""` (not null) in THIS request's `check` response, even though the
    // column just written to the DB is NULL. Real, preserved PHP inconsistency — see autoRegisterMember()
    // in api/member.php.
    display_name: displayName,
    points: 50,
  };
}

/** Port of api/member.php::autoUpgradeMember(). */
export async function autoUpgradeMember(db: Kysely<TenantDB>, userId: number, lineAccountId: number): Promise<CheckUserRow> {
  const memberId = await generateMemberId(db, lineAccountId);
  const existingColumns = await getExistingUserColumns(db);

  const updateFragments = [sql`member_id = ${memberId}`, sql`is_registered = 1`, sql`registered_at = NOW()`];
  if (existingColumns.has('member_tier')) updateFragments.push(sql`member_tier = 'bronze'`);
  if (existingColumns.has('points')) updateFragments.push(sql`points = COALESCE(points, 0) + 50`);

  await sql`UPDATE users SET ${sql.join(updateFragments)} WHERE id = ${userId}`.execute(db);

  try {
    await sql`
      INSERT INTO points_history (line_account_id, user_id, points, type, description, balance_after)
      VALUES (${lineAccountId}, ${userId}, 50, 'bonus', 'โบนัสต้อนรับสมาชิก (Auto-Upgrade)',
        (SELECT COALESCE(points, 50) FROM users WHERE id = ${userId}))
    `.execute(db);
  } catch {
    // best-effort, matches PHP's catch.
  }

  const result = await sql<CheckUserRow>`
    SELECT id, member_id, is_registered, first_name, last_name, display_name, points FROM users WHERE id = ${userId}
  `.execute(db);
  const row = result.rows[0];
  if (!row) {
    // Extremely unlikely (row was just UPDATEd above) — surfaces as a 500, same as an uncaught
    // PDOException would in the PHP original (this SELECT has no try/catch there either).
    throw new Error(`autoUpgradeMember: user id=${userId} not found after UPDATE`);
  }
  return row;
}

export async function handleCheck(
  db: Kysely<TenantDB>,
  lineAccountId: number,
  query: Record<string, unknown>
): Promise<ActionResult> {
  const lineUserId = strOrEmpty(query.line_user_id);
  const displayName = strOrEmpty(query.display_name);
  const pictureUrl = strOrEmpty(query.picture_url);

  if (!lineUserId) {
    return ok(false, 'Missing line_user_id');
  }

  let user = await findUserForCheck(db, lineUserId, lineAccountId);

  if (!user) {
    user = await autoRegisterMember(db, lineUserId, lineAccountId, displayName, pictureUrl);
  }

  if (user && !user.is_registered) {
    user = await autoUpgradeMember(db, user.id, lineAccountId);
  }

  const hasProfile = Boolean(user.first_name);
  const points = Number(user.points ?? 0);
  const tierInfo = await calculateTier(db, lineAccountId, points);

  return ok(true, 'OK', {
    exists: true,
    is_registered: Boolean(user.is_registered),
    has_profile: hasProfile,
    member_id: user.member_id ?? null,
    first_name: user.first_name ?? null,
    last_name: user.last_name ?? null,
    display_name: user.display_name ?? null,
    tier: tierInfo.tier_code,
    tier_name: tierInfo.tier_name,
    points,
    // Hardcoded true on every success response, regardless of whether auto-register/auto-upgrade
    // actually fired THIS request — verbatim quirk from api/member.php::handleCheck(), preserved.
    auto_registered: true,
  });
}

// ---------------------------------------------------------------------------
// action=get_card
// ---------------------------------------------------------------------------

interface GetCardUserRow {
  id: number;
  member_id: string | null;
  is_registered: number;
  first_name: string | null;
  last_name: string | null;
  display_name: string | null;
  picture_url: string | null;
  phone: string | null;
  email: string | null;
  birthday: string | Date | null;
  gender: string | null;
  address: string | null;
  district: string | null;
  province: string | null;
  postal_code: string | null;
  weight: number | string | null;
  height: number | string | null;
  medical_conditions: string | null;
  drug_allergies: string | null;
  total_spent: number | string | null;
  total_orders: number | string | null;
  registered_at: string | Date | null;
}

async function findUserForGetCard(db: Kysely<TenantDB>, lineUserId: string, lineAccountId: number): Promise<GetCardUserRow | null> {
  const scoped = await sql<GetCardUserRow>`
    SELECT * FROM users WHERE line_user_id = ${lineUserId} AND line_account_id = ${lineAccountId}
  `.execute(db);
  if (scoped.rows.length > 0) return scoped.rows[0]!;

  const unscoped = await sql<GetCardUserRow>`SELECT * FROM users WHERE line_user_id = ${lineUserId}`.execute(db);
  return unscoped.rows[0] ?? null;
}

async function getShopInfo(db: Kysely<TenantDB>, lineAccountId: number): Promise<{ shop_name: string | null; logo_url: string | null } | null> {
  try {
    const hasLogoCol = await sql<{ Field: string }>`SHOW COLUMNS FROM shop_settings LIKE 'logo_url'`.execute(db);
    if (hasLogoCol.rows.length > 0) {
      const result = await sql<{ shop_name: string | null; logo_url: string | null }>`
        SELECT shop_name, logo_url FROM shop_settings WHERE line_account_id = ${lineAccountId} LIMIT 1
      `.execute(db);
      return result.rows[0] ?? null;
    }
    const result = await sql<{ shop_name: string | null; logo_url: string }>`
      SELECT shop_name, '' as logo_url FROM shop_settings WHERE line_account_id = ${lineAccountId} LIMIT 1
    `.execute(db);
    return result.rows[0] ?? null;
  } catch {
    return null;
  }
}

export async function handleGetCard(
  db: Kysely<TenantDB>,
  lineAccountId: number,
  query: Record<string, unknown>
): Promise<ActionResult> {
  const lineUserId = strOrEmpty(query.line_user_id);
  if (!lineUserId) {
    return ok(false, 'Missing line_user_id');
  }

  const user = await findUserForGetCard(db, lineUserId, lineAccountId);
  if (!user) {
    return ok(false, 'ไม่พบข้อมูลผู้ใช้', { is_registered: false, user_exists: false });
  }
  if (!user.is_registered) {
    return ok(false, 'ยังไม่ได้ลงทะเบียนสมาชิก', { is_registered: false, user_exists: true, user_id: user.id });
  }

  const pointsData = await getUserPoints(db, user.id);
  const userPoints = pointsData.available_points;

  const tierInfo = await calculateTier(db, lineAccountId, userPoints);

  const tier = {
    tier_code: tierInfo.tier_code,
    tier_name: tierInfo.tier_name,
    name: tierInfo.tier_name,
    color: tierInfo.color,
    icon: tierInfo.icon,
    discount_percent: tierInfo.discount_percent,
    min_points: tierInfo.min_points,
    current_tier_points: tierInfo.min_points,
    next_tier_points: tierInfo.next_tier_points,
    next_tier_name: tierInfo.next_tier_name,
    points_to_next: tierInfo.points_to_next,
    progress_percent: tierInfo.progress_percent,
  };

  const nextTier = tierInfo.next_tier_code
    ? { tier_code: tierInfo.next_tier_code, tier_name: tierInfo.next_tier_name, min_points: tierInfo.next_tier_points }
    : null;

  const shop = await getShopInfo(db, lineAccountId);
  const lineAccountResult = await sql<{ name: string }>`SELECT name FROM line_accounts WHERE id = ${lineAccountId} LIMIT 1`.execute(db);
  const lineAccountRow = lineAccountResult.rows[0] ?? null;
  const shopName = shop?.shop_name || lineAccountRow?.name || 'ร้านค้า';

  return ok(true, 'OK', {
    member: {
      id: user.id,
      member_id: user.member_id,
      is_registered: Boolean(user.is_registered),
      first_name: user.first_name,
      last_name: user.last_name,
      display_name: user.display_name,
      picture_url: user.picture_url,
      phone: user.phone,
      email: user.email ?? null,
      birthday: asDateString(user.birthday),
      gender: user.gender,
      address: user.address ?? null,
      district: user.district ?? null,
      province: user.province ?? null,
      postal_code: user.postal_code ?? null,
      weight: user.weight === null || user.weight === undefined ? null : Number(user.weight),
      height: user.height === null || user.height === undefined ? null : Number(user.height),
      medical_conditions: user.medical_conditions ?? null,
      drug_allergies: user.drug_allergies ?? null,
      points: userPoints,
      total_spent: Number(user.total_spent ?? 0),
      total_orders: Number(user.total_orders ?? 0),
      registered_at: asDateTimeString(user.registered_at),
    },
    tier,
    next_tier: nextTier,
    shop: { name: shopName, logo: shop?.logo_url ?? '' },
  });
}

// ---------------------------------------------------------------------------
// action=register
// ---------------------------------------------------------------------------

interface RegisterUserRow {
  id: number;
  member_id: string | null;
  is_registered: number;
  line_account_id: number | null;
}

async function findUserForRegister(db: Kysely<TenantDB>, lineUserId: string, lineAccountId: number): Promise<RegisterUserRow | null> {
  const scoped = await sql<RegisterUserRow>`
    SELECT id, member_id, is_registered, line_account_id FROM users WHERE line_user_id = ${lineUserId} AND line_account_id = ${lineAccountId}
  `.execute(db);
  if (scoped.rows.length > 0) return scoped.rows[0]!;

  const unscoped = await sql<RegisterUserRow>`
    SELECT id, member_id, is_registered, line_account_id FROM users WHERE line_user_id = ${lineUserId}
  `.execute(db);
  return unscoped.rows[0] ?? null;
}

/**
 * Port of api/member.php::flagPointsMergeOnLink() — best-effort, never throws into the caller. The
 * `CREATE TABLE IF NOT EXISTS` here is an INHERITED quirk from the PHP original (mirrors an
 * auto-create-table pattern CLAUDE.md discourages for NEW admin features) — kept for byte-fidelity of
 * an EXISTING endpoint's side effects, not a template for new Next.js features.
 */
export async function flagPointsMergeOnLink(
  db: Kysely<TenantDB>,
  lineAccountId: number,
  lineUserDbId: number,
  phone: string
): Promise<void> {
  try {
    let digits = phone.replace(/\D+/g, '');
    if (digits.length === 11 && digits.startsWith('66')) {
      digits = '0' + digits.slice(2);
    }
    if (digits.length < 8 || lineAccountId <= 0 || lineUserDbId <= 0) {
      return;
    }

    const ghostResult = await sql<{ id: number; available_points: number | string }>`
      SELECT id, available_points FROM users
      WHERE line_account_id = ${lineAccountId} AND line_user_id = ${'offline:' + digits} AND available_points > 0
      LIMIT 1
    `.execute(db);
    const ghost = ghostResult.rows[0];
    if (!ghost) return;
    const offlineId = Number(ghost.id);
    if (offlineId === lineUserDbId) return;

    await sql`
      CREATE TABLE IF NOT EXISTS \`points_merge_candidates\` (
        \`id\` INT NOT NULL AUTO_INCREMENT,
        \`line_account_id\` INT NOT NULL,
        \`phone\` VARCHAR(20) NOT NULL,
        \`offline_user_id\` INT NOT NULL,
        \`line_user_id\` INT NOT NULL,
        \`offline_points\` INT NOT NULL DEFAULT 0,
        \`status\` ENUM('pending','merged','dismissed') NOT NULL DEFAULT 'pending',
        \`created_at\` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        \`resolved_at\` TIMESTAMP NULL DEFAULT NULL,
        \`resolved_by\` INT NULL,
        PRIMARY KEY (\`id\`),
        UNIQUE KEY \`uniq_pair\` (\`line_account_id\`, \`offline_user_id\`, \`line_user_id\`),
        KEY \`idx_account_status\` (\`line_account_id\`, \`status\`),
        KEY \`idx_phone\` (\`line_account_id\`, \`phone\`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    `.execute(db);

    await sql`
      INSERT INTO points_merge_candidates (line_account_id, phone, offline_user_id, line_user_id, offline_points, status)
      VALUES (${lineAccountId}, ${digits}, ${offlineId}, ${lineUserDbId}, ${ghost.available_points}, 'pending')
      ON DUPLICATE KEY UPDATE offline_points = VALUES(offline_points),
        status = IF(status = 'merged', 'merged', 'pending'), resolved_at = NULL, resolved_by = NULL
    `.execute(db);
  } catch {
    // Best-effort — never throws into the caller, mirrors PHP's catch (Throwable $e) { error_log(...) }.
  }
}

export async function handleRegister(
  db: Kysely<TenantDB>,
  lineAccountId: number,
  data: Record<string, unknown>
): Promise<ActionResult> {
  const lineUserId = strOrEmpty(data.line_user_id);
  if (!lineUserId) {
    return ok(false, 'กรุณาเข้าสู่ระบบผ่าน LINE');
  }

  const firstName = strOrEmpty(data.first_name);
  const lastName = strOrEmpty(data.last_name);
  const birthday = (data.birthday as string | undefined) ?? null;
  const gender = (data.gender as string | undefined) ?? null;

  if (!firstName) return ok(false, 'กรุณากรอกชื่อ');
  if (!birthday) return ok(false, 'กรุณากรอกวันเกิด');
  if (!gender) return ok(false, 'กรุณาเลือกเพศ');

  const phone = strOrEmpty(data.phone);
  const email = strOrEmpty(data.email);
  const weight = floatOrNull(data.weight);
  const height = floatOrNull(data.height);
  const medicalConditions = strOrEmpty(data.medical_conditions);
  const drugAllergies = strOrEmpty(data.drug_allergies);
  const address = strOrEmpty(data.address);
  const district = strOrEmpty(data.district);
  const province = strOrEmpty(data.province);
  const postalCode = strOrEmpty(data.postal_code);

  const existingColumns = await getExistingUserColumns(db);
  const user = await findUserForRegister(db, lineUserId, lineAccountId);

  if (user && user.is_registered) {
    return ok(false, 'คุณเป็นสมาชิกอยู่แล้ว', { member_id: user.member_id });
  }

  const memberId = await generateMemberId(db, lineAccountId);
  const realName = firstName + (lastName ? ' ' + lastName : '');
  const phoneValue = phpEmpty(phone) ? null : phone;
  const emailValue = phpEmpty(email) ? null : email;

  let userId: number;

  if (user) {
    const updateFragments = [
      sql`first_name = ${firstName}`,
      sql`last_name = ${lastName}`,
      sql`real_name = ${realName}`,
      sql`birthday = ${birthday}`,
      sql`gender = ${gender}`,
      sql`phone = IFNULL(${phoneValue}, phone)`,
      sql`weight = ${weight}`,
      sql`height = ${height}`,
      sql`medical_conditions = ${medicalConditions}`,
      sql`drug_allergies = ${drugAllergies}`,
      sql`member_id = ${memberId}`,
      sql`is_registered = 1`,
      sql`registered_at = NOW()`,
      sql`updated_at = NOW()`,
    ];
    if (existingColumns.has('member_tier')) updateFragments.push(sql`member_tier = 'bronze'`);
    if (existingColumns.has('points')) updateFragments.push(sql`points = 0`);
    if (existingColumns.has('email')) updateFragments.push(sql`email = IFNULL(${emailValue}, email)`);
    if (existingColumns.has('address')) updateFragments.push(sql`address = ${address || null}`);
    if (existingColumns.has('district')) updateFragments.push(sql`district = ${district || null}`);
    if (existingColumns.has('province')) updateFragments.push(sql`province = ${province || null}`);
    if (existingColumns.has('postal_code')) updateFragments.push(sql`postal_code = ${postalCode || null}`);

    await sql`UPDATE users SET ${sql.join(updateFragments)} WHERE id = ${user.id}`.execute(db);
    userId = user.id;
  } else {
    const fields: InsertField[] = [
      { column: 'line_account_id', value: lineAccountId },
      { column: 'line_user_id', value: lineUserId },
      { column: 'first_name', value: firstName },
      { column: 'last_name', value: lastName },
      { column: 'real_name', value: realName },
      { column: 'birthday', value: birthday },
      { column: 'gender', value: gender },
      { column: 'phone', value: phone || null },
      { column: 'weight', value: weight },
      { column: 'height', value: height },
      { column: 'medical_conditions', value: medicalConditions || null },
      { column: 'drug_allergies', value: drugAllergies || null },
      { column: 'member_id', value: memberId },
      { column: 'is_registered', value: 1 },
    ];
    if (existingColumns.has('member_tier')) fields.push({ column: 'member_tier', value: 'bronze' });
    if (existingColumns.has('points')) fields.push({ column: 'points', value: 0 });
    fields.push({ column: 'registered_at', value: sql.raw('NOW()') });
    fields.push({ column: 'created_at', value: sql.raw('NOW()') });
    if (existingColumns.has('email') && email) fields.push({ column: 'email', value: email });
    if (existingColumns.has('address') && address) fields.push({ column: 'address', value: address });
    if (existingColumns.has('district') && district) fields.push({ column: 'district', value: district });
    if (existingColumns.has('province') && province) fields.push({ column: 'province', value: province });
    if (existingColumns.has('postal_code') && postalCode) fields.push({ column: 'postal_code', value: postalCode });

    userId = await insertRow(db, 'users', fields);
  }

  if (phone) {
    await flagPointsMergeOnLink(db, lineAccountId, userId, phone);
  }

  const welcomeBonus = 50;
  try {
    await sql`UPDATE users SET points = ${welcomeBonus} WHERE id = ${userId}`.execute(db);
  } catch {
    // points column might not exist — best-effort, matches PHP's catch.
  }

  try {
    await sql`
      INSERT INTO points_history (line_account_id, user_id, points, type, description, balance_after)
      VALUES (${lineAccountId}, ${userId}, ${welcomeBonus}, 'bonus', 'โบนัสต้อนรับสมาชิกใหม่', ${welcomeBonus})
    `.execute(db);
  } catch {
    // points_history table might not exist — best-effort.
  }

  return ok(true, 'สมัครสมาชิกสำเร็จ!', { member_id: memberId, welcome_bonus: welcomeBonus, tier: 'bronze' });
}

// ---------------------------------------------------------------------------
// action=update_profile
// ---------------------------------------------------------------------------

const UPDATE_PROFILE_ALLOWED_FIELDS = [
  'first_name',
  'last_name',
  'phone',
  'email',
  'weight',
  'height',
  'medical_conditions',
  'drug_allergies',
  'address',
  'district',
  'province',
  'postal_code',
  'birthday',
  'gender',
] as const;

export async function handleUpdateProfile(db: Kysely<TenantDB>, data: Record<string, unknown>): Promise<ActionResult> {
  const lineUserId = strOrEmpty(data.line_user_id);
  if (!lineUserId) {
    return ok(false, 'กรุณาเข้าสู่ระบบ');
  }

  const updateFragments: ReturnType<typeof sql>[] = [];
  for (const field of UPDATE_PROFILE_ALLOWED_FIELDS) {
    // PHP's `isset($data[$field])` is false for both an absent key AND an explicit null.
    if (data[field] !== undefined && data[field] !== null) {
      updateFragments.push(sql`${sql.ref(field)} = ${data[field]}`);
    }
  }

  if (updateFragments.length === 0) {
    return ok(false, 'ไม่มีข้อมูลที่ต้องอัพเดท');
  }

  if ((data.first_name !== undefined && data.first_name !== null) || (data.last_name !== undefined && data.last_name !== null)) {
    const firstName = typeof data.first_name === 'string' ? data.first_name : '';
    const lastName = typeof data.last_name === 'string' ? data.last_name : '';
    const realName = `${firstName} ${lastName}`.trim();
    updateFragments.push(sql`real_name = ${realName}`);
  }

  await sql`UPDATE users SET ${sql.join(updateFragments)}, updated_at = NOW() WHERE line_user_id = ${lineUserId}`.execute(db);

  if (!phpEmpty(data.phone)) {
    try {
      const rowResult = await sql<{ id: number; line_account_id: number | null }>`
        SELECT id, line_account_id FROM users WHERE line_user_id = ${lineUserId} LIMIT 1
      `.execute(db);
      const row = rowResult.rows[0];
      if (row) {
        await flagPointsMergeOnLink(db, Number(row.line_account_id ?? 0), row.id, String(data.phone));
      }
    } catch {
      // best-effort, matches PHP's catch (Throwable $e) { error_log(...) }.
    }
  }

  return ok(true, 'อัพเดทข้อมูลสำเร็จ');
}
